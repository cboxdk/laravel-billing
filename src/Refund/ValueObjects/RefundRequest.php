<?php

declare(strict_types=1);

namespace Cbox\Billing\Refund\ValueObjects;

use Cbox\Billing\Invoice\ValueObjects\Invoice;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Refund\Enums\RefundReason;
use Cbox\Billing\Wallet\ValueObjects\CreditGrant;
use DateTimeImmutable;

/**
 * A request to refund (part of) an issued invoice.
 *
 * `id` is the caller-owned idempotency key for the whole operation (e.g. a webhook
 * delivery id or an operator action id): re-submitting the same id is a no-op that
 * returns the already-issued {@see Refund}, so a retry or a re-delivered event never
 * refunds twice and never burns a second credit-note number.
 *
 * A `net` of `null` means a FULL refund (mirror every invoice line). A `net` set means
 * a PARTIAL refund of that net amount; the tax is reversed proportionally to the
 * invoice's tax-to-net ratio, so a partial refund reverses tax in proportion.
 *
 * `reverseGrant` optionally names a credit grant issued by the original purchase; when
 * present the refund reverses it with an offsetting grant (never a silent balance
 * edit) — see {@see reversingGrant()}.
 */
readonly class RefundRequest
{
    public function __construct(
        public string $id,
        public string $account,
        public Invoice $invoice,
        public RefundReason $reason,
        public DateTimeImmutable $at,
        public ?Money $net = null,
        public ?string $originalGatewayReference = null,
        public ?CreditGrant $reverseGrant = null,
        public ?int $reverseGrantUnits = null,
    ) {}

    /** A full refund of `$invoice` — every line mirrored negatively, tax and all. */
    public static function full(
        string $id,
        string $account,
        Invoice $invoice,
        RefundReason $reason,
        DateTimeImmutable $at,
        ?string $originalGatewayReference = null,
    ): self {
        return new self($id, $account, $invoice, $reason, $at, null, $originalGatewayReference);
    }

    /** A partial refund of `$net` (excl. tax); tax is reversed proportionally. */
    public static function partial(
        string $id,
        string $account,
        Invoice $invoice,
        Money $net,
        RefundReason $reason,
        DateTimeImmutable $at,
        ?string $originalGatewayReference = null,
    ): self {
        return new self($id, $account, $invoice, $reason, $at, $net, $originalGatewayReference);
    }

    /**
     * Also reverse `$grant` (a credit grant issued by the original purchase) by
     * `$units` of its denomination — defaulting to the grant's current remainder. The
     * refunder deposits an offsetting grant rather than mutating any balance.
     */
    public function reversingGrant(CreditGrant $grant, ?int $units = null): self
    {
        return new self(
            $this->id,
            $this->account,
            $this->invoice,
            $this->reason,
            $this->at,
            $this->net,
            $this->originalGatewayReference,
            $grant,
            $units,
        );
    }

    /** Whether this is a full refund (no partial net specified). */
    public function isFull(): bool
    {
        return $this->net === null;
    }
}
