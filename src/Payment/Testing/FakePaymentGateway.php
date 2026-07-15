<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\Testing;

use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Cbox\Billing\Payment\ValueObjects\PaymentIntent;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;

/**
 * A scripted gateway for tests — returns a configured result and records the
 * intents it was asked to charge.
 */
class FakePaymentGateway implements PaymentGateway
{
    /** @var list<PaymentIntent> */
    public array $charged = [];

    public function __construct(private PaymentResult $result) {}

    public function name(): string
    {
        return 'fake';
    }

    public function charge(PaymentIntent $intent): PaymentResult
    {
        $this->charged[] = $intent;

        return $this->result;
    }
}
