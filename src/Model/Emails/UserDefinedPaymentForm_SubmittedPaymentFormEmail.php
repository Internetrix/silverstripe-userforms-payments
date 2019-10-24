<?php

namespace SoulDigital\UserformPayments\Model\Emails;

use SilverStripe\Omnipay\Model\Payment;
use SilverStripe\UserForms\Model\Recipient\EmailRecipient;

/**
 * Email that gets sent to the people listed in the Email Recipients when a
 * submission is made.
 *
 * @package userforms
 */
class UserDefinedPaymentForm_SubmittedPaymentFormEmail extends EmailRecipient
{
    protected $ss_template = "email/SubmittedPaymentFormEmail";

    private static $table_name = 'UserDefinedPaymentForm_SubmittedPaymentFormEmail';

    /*
    public function Body()
    {
        return str_replace("[amount]", $this->PaymentAmount(), $this->body);
    }
    */

    public function Payment()
    {
        return Payment::get()->byID($this->PaymentID);
    }

    public function PaymentAmount()
    {
        if ($payment = $this->Payment())
            return "$" . substr($payment->getAmount(), 0, -2);
        return "$0";
    }

    public function PaymentStatus()
    {
        if ($payment = $this->Payment())
            return $payment->Status;
        return "Error";
    }
}