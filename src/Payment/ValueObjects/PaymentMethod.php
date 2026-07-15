<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\ValueObjects;

/**
 * A saved payment method attached to a billing account, gateway-agnostic and card-data
 * free — only the non-sensitive display fields the engine may hold (never the PAN, never
 * the CVC). The gateway owns the vault; this is the reconciliation/display handle.
 *
 * `$id` is the gateway's token for the method. `$expMonth` / `$expYear` are null for a
 * non-card method (e.g. an off-line/bank arrangement) that has no card expiry. Exactly
 * one method per account is the default the off-session renewal charges.
 */
readonly class PaymentMethod
{
    public function __construct(
        public string $id,
        public string $brand,
        public string $last4,
        public ?int $expMonth,
        public ?int $expYear,
        public bool $isDefault = false,
    ) {}
}
