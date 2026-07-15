<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\Gateways;

use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Cbox\Billing\Payment\ValueObjects\PaymentIntent;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;

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
}
