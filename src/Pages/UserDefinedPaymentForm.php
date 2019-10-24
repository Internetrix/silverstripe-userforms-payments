<?php

namespace SoulDigital\UserformPayments\Pages;

use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\CompositeField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\HTMLEditor\HTMLEditorField;
use SilverStripe\Forms\LabelField;
use SilverStripe\Forms\TextField;
use SilverStripe\Omnipay\GatewayInfo;
use SilverStripe\ORM\ArrayList;
use SilverStripe\UserForms\Model\EditableFormField;
use SilverStripe\UserForms\Model\UserDefinedForm;

/**
 * A UserDefinedForm Page object with extra fields for payments
 *
 * @package userforms-payments
 */
class UserDefinedPaymentForm extends UserDefinedForm
{
    private static $description = 'A user defined form page that accepts payments';

    private static $db = [
        "PaymentGateway"         => "Varchar",
        "PaymentCurrency"        => "Varchar(3)",
        "PaymentFields_Card"     => "Boolean",
        "PaymentFields_Billing"  => "Boolean",
        "PaymentFields_Shipping" => "Boolean",
        "PaymentFields_Company"  => "Boolean",
        "PaymentFields_Email"    => "Boolean",
        "OnErrorMessage"         => "HTMLText",
    ];

    private static $has_one = [
        "PaymentAmountField" => EditableFormField::class,
    ];

    private static $defaults = [
        "PaymentCurrency"   => "AUD",
        "OnErrorMessage"    => "<p>Sorry, your payment could not be processed. Your credit card has not been charged. Please try again.</p>",
        "OnCompleteMessage" => "<p>Thank you. Your payment of [amount] has been processed.</p>"
    ];

    public function getCMSFields()
    {
        $fields       = parent::getCMSFields();
        $gateways     = GatewayInfo::getSupportedGateways();
        $amountfields = $this->Fields()->map("ID", "Title");
        $fields->addFieldsToTab("Root.Payment",
            array(
                DropdownField::create("PaymentAmountFieldID", "Payment Amount Field", $amountfields)->setDescription("This must return a value like 20.00 (no dollar sign)"),
                new DropdownField("PaymentGateway", "Payment Gateway", $gateways),
                new TextField("PaymentCurrency", "Payment Currency"),
                new CheckboxField("PaymentFields_Card", "Show Card Fields"),
                new CheckboxField("PaymentFields_Billing", "Show Billing Fields"),
                new CheckboxField("PaymentFields_Shipping", "Show Shipping Fields"),
                new CheckboxField("PaymentFields_Company", "Show Company Fields"),
                new CheckboxField("PaymentFields_Email", "Show Email Fields")
            )
        );

        // text to show on error
        $onErrorFieldSet = new CompositeField(
            $label = new LabelField('OnErrorMessageLabel', _t('UserDefinedForm.ONERRORLABEL', 'Show on error')),
            $editor = new HTMLEditorField("OnErrorMessage", "", _t('UserDefinedForm.ONERRORMESSAGE', $this->OnErrorMessage))
        );

        $onErrorFieldSet->addExtraClass('field');
        $fields->insertAfter($onErrorFieldSet, "OnCompleteMessage");

	    $this->extend('updateUserDefinedPaymentFormCMSFields', $fields);

        return $fields;
    }

	/**
	 * @return ArrayList
	 */
	public function FilteredEmailRecipients($data = null, $form = null, $status = null)
	{
		$recipients = parent::FilteredEmailRecipients($data, $form);

		// @todo - rework in the original logic so extensions can manipulate this recipients list
		$recipients = $recipients->filterByCallback(function ($item, $list) use ($status) {
			return $item->SendForStatus($status);
		});

		return $recipients;
	}
}