<?php

namespace SoulDigital\UserformPayments\Model\Submissions;

use SilverStripe\Omnipay\Model\Payment;
use SilverStripe\UserForms\Model\Submission\SubmittedForm;

/**
 * Contents of an UserDefinedForm submission with a relational link to the payment object
 *
 * @package userforms-payments
 */
class SubmittedPaymentForm extends SubmittedForm
{
    private static $has_one = [
        "Payment" => Payment::class
    ];
}