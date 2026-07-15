<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\ValueObjects;

use Cbox\Billing\Payment\Enums\PaymentStatus;

/**
 * The result of a payment attempt: the status, the gateway's own reference (for
 * reconciliation), and a failure reason when it failed.
 */
readonly class PaymentResult
{
    public function __construct(
        public PaymentStatus $status,
        public ?string $gatewayReference = null,
        public ?string $failureReason = null,
    ) {}

    public static function succeeded(string $gatewayReference): self
    {
        return new self(PaymentStatus::Succeeded, $gatewayReference);
    }

    public static function pending(?string $gatewayReference = null): self
    {
        return new self(PaymentStatus::Pending, $gatewayReference);
    }

    public static function failed(string $reason): self
    {
        return new self(PaymentStatus::Failed, failureReason: $reason);
    }

    public function isSettled(): bool
    {
        return $this->status->isSettled();
    }
}
