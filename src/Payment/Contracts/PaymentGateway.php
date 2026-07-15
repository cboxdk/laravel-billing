<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\Contracts;

use Cbox\Billing\Payment\ValueObjects\PaymentIntent;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;

/**
 * A payment gateway. The engine is gateway-agnostic: Stripe, Mollie and manual/
 * bank-transfer gateways all implement this. Concrete SDK-backed gateways
 * (Stripe, Mollie) ship as opt-in adapter packages so the core stays dependency-light.
 */
interface PaymentGateway
{
    public function name(): string;

    public function charge(PaymentIntent $intent): PaymentResult;
}
