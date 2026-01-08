<?php

namespace Curbstone\Payment;

use Curbstone\Gateways\CreditCard;

class CreditCardPaymentMethod implements PaymentMethodInterface
{
    public function getName(): string
    {
        return 'Curbstone Credit Card';
    }

    public function getDescription(): string
    {
        return 'Pay by credit card via Curbstone (pre-authorization).';
    }

    public function getPaymentHandler(): string
    {
        return CreditCard::class;
    }

    public function getTechnicalName(): string
    {
        return 'curbstone_credit_card';
    }

    public function afterOrderEnabled(): bool
    {
        return false;
    }
}
