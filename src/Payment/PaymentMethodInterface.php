<?php

declare(strict_types=1);

namespace Curbstone\Payment;

interface PaymentMethodInterface
{
    /**
     * Return name of the payment method.
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Return the description of the payment method.
     *
     * @return string
     */
    public function getDescription(): string;

    /**
     * Return the payment handler of a plugin.
     *
     * @return string
     */
    public function getPaymentHandler(): string;
    public function getTechnicalName(): string;
    public function afterOrderEnabled(): bool;
}
