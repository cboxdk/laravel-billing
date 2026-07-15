<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\Testing;

use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Cbox\Billing\Payment\ValueObjects\PaymentIntent;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;
use Cbox\Billing\Payment\ValueObjects\RefundIntent;

/**
 * A scripted gateway for tests — returns configured results and records the intents
 * it was asked to charge and refund. When no refund result is scripted it settles the
 * refund successfully, echoing the intent's idempotency key as the gateway reference.
 */
class FakePaymentGateway implements PaymentGateway
{
    /** @var list<PaymentIntent> */
    public array $charged = [];

    /** @var list<RefundIntent> */
    public array $refunded = [];

    public function __construct(
        private PaymentResult $result,
        private ?PaymentResult $refundResult = null,
    ) {}

    public function name(): string
    {
        return 'fake';
    }

    public function charge(PaymentIntent $intent): PaymentResult
    {
        $this->charged[] = $intent;

        return $this->result;
    }

    public function refund(RefundIntent $intent): PaymentResult
    {
        $this->refunded[] = $intent;

        return $this->refundResult ?? PaymentResult::succeeded($intent->idempotencyKey);
    }
}
