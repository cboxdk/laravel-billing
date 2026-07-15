<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\Contracts;

use Cbox\Billing\Payment\ValueObjects\PaymentIntent;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;
use Cbox\Billing\Payment\ValueObjects\RefundIntent;

/**
 * A payment gateway. The engine is gateway-agnostic: a manual/bank-transfer gateway
 * and SDK-backed gateways all implement this. Concrete SDK-backed gateways ship as
 * opt-in adapter packages so the core stays dependency-light.
 */
interface PaymentGateway
{
    public function name(): string;

    public function charge(PaymentIntent $intent): PaymentResult;

    /**
     * Return a previously-captured amount. The `idempotencyKey` on the intent scopes
     * the refund so a retry (or a re-delivered webhook) collapses to one money
     * movement — the gateway must not refund twice for the same key. Voluntary refunds
     * flow through here; a chargeback does NOT (its money is pulled by the network out
     * of band, never issued by us).
     */
    public function refund(RefundIntent $intent): PaymentResult;
}
