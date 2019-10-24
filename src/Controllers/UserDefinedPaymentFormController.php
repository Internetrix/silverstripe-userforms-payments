<?php

namespace SoulDigital\UserformPayments\Controllers;

use SilverStripe\Assets\File;
use SilverStripe\Assets\Upload;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTP;
use SilverStripe\Forms\CompositeField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\RequiredFields;
use SilverStripe\Omnipay\Exception\Exception;
use SilverStripe\Omnipay\GatewayFieldsFactory;
use SilverStripe\Omnipay\Model\Message\PurchasedResponse;
use SilverStripe\Omnipay\Model\Payment;
use SilverStripe\Omnipay\Service\PurchaseService;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\ValidationException;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;
use SilverStripe\UserForms\Control\UserDefinedFormController;
use SilverStripe\UserForms\Form\UserForm;
use SoulDigital\UserformPayments\Model\Emails\UserDefinedPaymentForm_SubmittedPaymentFormEmail;
use SoulDigital\UserformPayments\Model\Submissions\SubmittedPaymentForm;

class UserDefinedPaymentFormController extends UserDefinedFormController
{
    private static $allowed_actions = [
        "index",
        "ping",
        "Form",
        "finished",
        "complete",
        "error"
    ];

    /**
     * Find all the omnipay fields that have been defined for this particular payment page
     *
     * @return array
     */
    private function getPaymentFieldsGroupArray()
    {
        $fields  = [];
        $options = ["Card", "Billing", "Shipping", "Company", "Email"];

        foreach ($options as $option) {
            $dbfield = "PaymentFields_" . $option;
            if ($this->data()->$dbfield)
                $fields[] = $option;
        }

        return $fields;
    }

    /**
     * Get the form for the page. Form can be modified by calling {@link updateForm()}
     * on a UserDefinedForm extension.
     *
     * @return Form
     */
    public function Form()
    {
        $form = UserForm::create($this);
        $form->setFields($this->getFormFields($form));
        $this->generateConditionalJavascript();
        return $form;
    }

    /**
     * Combine all the parent UserDefinedForm fields and the omnipay fields
     *
     * @return FieldList
     */
    public function getFormFields(Form $form)
    {
        $gateway     = $this->data()->PaymentGateway;
        $fieldgroups = $this->getPaymentFieldsGroupArray();
        $factory     = new GatewayFieldsFactory($gateway, $fieldgroups);
        $fields      = $form->Fields();
        $fields->add(CompositeField::create($factory->getFields())->addExtraClass($gateway . "_fields"));

//	    Debug::show($factory->getFields()); die();

        if ($address1 = $fields->fieldByName('billingAddress1')) $address1->setTitle("Address Line 1");
        if ($address2 = $fields->fieldByName('billingAddress2')) $address2->setTitle("Address Line 2");

        return $fields;
    }

    /**
     * Make sure all omnipay fields are required
     *
     * @todo: make this more flexible
     *
     * @return RequiredFields
     */
    public function getRequiredFields()
    {
        $required    = parent::getRequiredFields();
        $gateway     = $this->data()->PaymentGateway;
        $fieldgroups = $this->getPaymentFieldsGroupArray();
        $factory     = new GatewayFieldsFactory($gateway, $fieldgroups);
        $fields      = $factory->getFields();
        foreach ($fields as $field) {
            if (!$field->hasMethod('getName')) continue;
            $fieldname = $field->getName();
            if ($fieldname == "billingAddress2") continue;
            $required->addRequiredField($fieldname);
        }

        $paymentfieldname = $this->PaymentAmountField()->Name;
        $required->addRequiredField($paymentfieldname);
        return $required;
    }

    /**
     * Process the form that is submitted through the site. Note that omnipay fields are NOT saved to the database.
     * This is intentional (so we don't save credit card details) but should be fixed in future, so we save all fields,
     * but only save the last 3 digits of the credit card (and not the CVV/exp date)
     *
     * @todo: save all fields to database except credit card fields
     *
     * @param array $data
     * @param Form  $form
     *
     * @return Redirection
     */
    public function process($data, $form)
    {
        $session = $this->getRequest()->getSession();
        $session->set("FormInfo.{$form->FormName()}.data", $data);
        $session->clear("FormInfo.{$form->FormName()}.errors");

        // if there are no errors, create the payment
//        $submittedForm                = Object::create('SubmittedPaymentForm');
        $submittedForm                = SubmittedPaymentForm::create();
        $submittedForm->SubmittedByID = ($id = Member::currentUserID()) ? $id : 0;
        $submittedForm->ParentID      = $this->ID;

        // if saving is not disabled save now to generate the ID
        if (!$this->DisableSaveSubmissions) {
            $submittedForm->write();
        }

        $attachments = [];

        $submittedFields = new ArrayList();

        foreach ($this->Fields() as $field) {
            if (!$field->showInReports()) {
                continue;
            }

            $submittedField           = $field->getSubmittedFormField();
            $submittedField->ParentID = $submittedForm->ID;
            $submittedField->Name     = $field->Name;
            $submittedField->Title    = $field->getField('Title');

            // save the value from the data
            if ($field->hasMethod('getValueFromData')) {
                $submittedField->Value = $field->getValueFromData($data);
            } else {
                if (isset($data[$field->Name])) {
                    $submittedField->Value = $data[$field->Name];
                }
            }

            if (!empty($data[$field->Name])) {
                if (in_array("EditableFileField", $field->getClassAncestry())) {
                    if (isset($_FILES[$field->Name])) {
                        $foldername = $field->getFormField()->getFolderName();

                        // create the file from post data
                        $upload             = new Upload();
                        $file               = new File();
                        $file->ShowInSearch = 0;
                        try {
                            $upload->loadIntoFile($_FILES[$field->Name], $file, $foldername);
                        } catch (ValidationException $e) {
                            $validationResult = $e->getResult();
                            $form->addErrorMessage($field->Name, $validationResult->message(), 'bad');
                            Controller::curr()->redirectBack();
                            return;
                        }

                        // write file to form field
                        $submittedField->UploadedFileID = $file->ID;

                        // attach a file only if lower than 1MB
                        if ($file->getAbsoluteSize() < 1024 * 1024 * 1) {
                            $attachments[] = $file;
                        }
                    }
                }
            }

            $submittedField->extend('onPopulationFromField', $field);

            if (!$this->DisableSaveSubmissions) {
                $submittedField->write();
            }

            $submittedFields->push($submittedField);
        }

        /** Do the payment **/
        // move this up here for our redirect link
        $referrer = (isset($data['Referrer'])) ? '?referrer=' . urlencode($data['Referrer']) : "";

        // set amount
        $currency = $this->data()->PaymentCurrency;

        $paymentfieldname = $this->PaymentAmountField()->Name;
        $amount           = $data[$paymentfieldname];
        $postdata         = $data;

        // request payment
        $payment = Payment::create()->init($this->data()->PaymentGateway, $amount, $currency);
        $payment->setSuccessUrl($this->Link('finished') . $referrer);
        $payment->setFailureUrl($this->Link('finished') . $referrer);

        $payment->write();

        $service = PurchaseService::create($payment);

        // Initiate payment, get the result back
        try {
            $serviceResponse = $service->initiate($postdata);
        } catch (Exception $ex) {
            // error out when an exception occurs
            $this->error($ex->getMessage());
            return null;
        }

        // save payment to order
        $submittedForm->PaymentID = $payment->ID;
        $submittedForm->write();

        $emailData = array(
            "Sender" => Member::currentUser(),
            "Fields" => $submittedFields
        );

        $this->extend('updateEmailData', $emailData, $attachments);

        $submittedForm->extend('updateAfterProcess');

        $session->clear("FormInfo.{$form->FormName()}.errors");
        $session->clear("FormInfo.{$form->FormName()}.data");


        // set a session variable from the security ID to stop people accessing the finished method directly
        if (isset($data['SecurityID'])) {
            $session->set('FormProcessed', $data['SecurityID']);
        } else {
            // if the form has had tokens disabled we still need to set FormProcessed
            // to allow us to get through the finshed method
            if (!$this->Form()->getSecurityToken()->isEnabled()) {
                $randNum  = rand(1, 1000);
                $randHash = md5($randNum);
                $session->set('FormProcessed', $randHash);
                $session->set('FormProcessedNum', $randNum);
            }
        }

        if (!$this->DisableSaveSubmissions) {
            $session->set('userformssubmission' . $this->ID, $submittedForm->ID);
        }
//die();
        return $serviceResponse->redirectOrRespond();
    }

    /**
     *
     * @return mixed
     */
    public function finished()
    {
        $session = $this->getRequest()->getSession();

        $submission = $session->get('userformssubmission' . $this->ID);
        $amountnice = '$0';

        if ($submission) {
            $submission = SubmittedPaymentForm::get()->byId($submission);
            $submittedFields = new ArrayList($submission->Values()->toArray());

            if ($payment = $submission->Payment()) {
                $amountnice = '$' . substr($payment->getAmount(), 0, -2);
                $payment_status = $payment->Status;

                // @todo get $attachments from submission
                $attachments = [];

                if ($recipients = $this->FilteredEmailRecipients(null, null, $payment_status)) {
                    $this->SendEmailsToRecipients($recipients, $attachments, $submittedFields, $payment);
                }
            }
        }

        $referrer = isset($_GET['referrer']) ? urldecode($_GET['referrer']) : null;

        $formProcessed = $session->get('FormProcessed');
        if (!isset($formProcessed)) {
            return $this->redirect($this->Link() . $referrer);
        } else {
            $securityID = $session->get('SecurityID');
            // make sure the session matches the SecurityID and is not left over from another form
            if ($formProcessed != $securityID) {
                // they may have disabled tokens on the form
                $securityID = md5($session->get('FormProcessedNum'));
                if ($formProcessed != $securityID) {
                    return $this->redirect($this->Link() . $referrer);
                }
            }
        }
        // remove the session variable as we do not want it to be re-used
        $session->clear('FormProcessed');
        $session->clear('userformssubmission' . $this->ID);
        $successmessage = str_replace("[amount]", $amountnice, $this->data()->OnCompleteMessage);
        return $this->customise([
            'Content' => $this->customise([
                'Submission'       => $submission,
                'Link'             => $referrer,
                'OnSuccessMessage' => $successmessage,
                'AmountNice'       => $amountnice
            ])->renderWith('ReceivedPaymentFormSubmission'),
            'Form'      => ($payment_status=="Captured")?'':$this->Form(),
            'IsFinished'  => true
        ]);
    }

    /**
     * We need to offset the sending of emails so we can change the content based on the payment status
     *
     * @param $recipients
     * @param $attachments
     * @param $submittedFields
     * @param $payment
     */
    function SendEmailsToRecipients($recipients, $attachments, $submittedFields, $payment){
        $email            = new UserDefinedPaymentForm_SubmittedPaymentFormEmail($submittedFields);
        $email->PaymentID = $payment->ID;
        $receipt_number   = "";
        if ($purchased_response = PurchasedResponse::get()->filter("PaymentID", $payment->ID)->first()) {
            $receipt_number = $purchased_response->Reference;
        }
        $emailData        = [
            "Sender" => Security::getCurrentUser(),
            "Fields" => $submittedFields,
            "Payment" => $payment,
            "ReceiptNumber" => $receipt_number
        ];

        if ($attachments) {
            foreach ($attachments as $file) {
                if ($file->ID != 0) {
                    $email->attachFile(
                        $file->Filename,
                        $file->Filename,
                        HTTP::get_mime_type($file->Filename)
                    );
                }
            }
        }

        foreach ($recipients as $recipient) {
            $email->populateTemplate($recipient);
            $email->populateTemplate($emailData);
            $email->setFrom($recipient->EmailFrom);
            $email->setBody($recipient->EmailBody);
            $email->setTo($recipient->EmailAddress);
            $email->setSubject($recipient->EmailSubject);

            if ($recipient->EmailReplyTo) {
                $email->setReplyTo($recipient->EmailReplyTo);
            }

            // check to see if they are a dynamic reply to. eg based on a email field a user selected
            if ($recipient->SendEmailFromField()) {
                $submittedFormField = $submittedFields->find('Name', $recipient->SendEmailFromField()->Name);

                if ($submittedFormField && is_string($submittedFormField->Value)) {
                    $email->setReplyTo($submittedFormField->Value);
                }
            }
            // check to see if they are a dynamic reciever eg based on a dropdown field a user selected
            if ($recipient->SendEmailToField()) {
                $submittedFormField = $submittedFields->find('Name', $recipient->SendEmailToField()->Name);

                if ($submittedFormField && is_string($submittedFormField->Value)) {
                    $email->setTo($submittedFormField->Value);
                }
            }

            // check to see if there is a dynamic subject
            if ($recipient->SendEmailSubjectField()) {
                $submittedFormField = $submittedFields->find('Name', $recipient->SendEmailSubjectField()->Name);

                if ($submittedFormField && trim($submittedFormField->Value)) {
                    $email->setSubject($submittedFormField->Value);
                }
            }

            $this->extend('updateEmail', $email, $recipient, $emailData);

            if ($recipient->SendPlain) {
                $body = strip_tags($recipient->EmailBody) . "\n";
                if (isset($emailData['Fields']) && !$recipient->HideFormData) {
                    foreach ($emailData['Fields'] as $Field) {
                        $body .= $Field->Title . ' - ' . $Field->Value . " \n";
                    }
                }

                $email->setBody($body);
                $email->sendPlain();
            } else {
                $email->send();
            }
        }
    }
}