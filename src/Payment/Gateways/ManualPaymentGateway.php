<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\Gateways;

use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Cbox\Billing\Payment\ValueObjects\PaymentIntent;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;
use Cbox\Billing\Payment\ValueObjects\RefundIntent;

/**
 * A manual / bank-transfer gateway: it records the intent as pending and leaves
 * settlement to happen out of band (the operator marks it paid when funds arrive).
 * The dependency-free default gateway.
 */
readonly class ManualPaymentGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'manual';
    }

    public function charge(PaymentIntent $intent): PaymentResult
    {
        return PaymentResult::pending($intent->id);
    }

    public function refund(RefundIntent $intent): PaymentResult
    {
        // A manual refund is paid out of band (the operator wires the money back);
        // record it as pending until settlement is confirmed.
        return PaymentResult::pending($intent->id);
    }
}
