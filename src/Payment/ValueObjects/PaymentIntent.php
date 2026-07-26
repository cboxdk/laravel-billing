<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\ValueObjects;

use Cbox\Billing\Money\Money;
use Cbox\Billing\Payment\Contracts\PaymentGateway;

/**
 * A request to collect an amount, tied to what it pays for (e.g. an invoice
 * number). Gateway-agnostic: gateways read the amount and reference.
 *
 * OFF-SESSION COLLECTION. A renewal is charged with nobody at the keyboard, so the gateway
 * needs to know WHOSE vaulted instrument to pull: `customerRef` is the gateway's own customer
 * handle (a Stripe `cus_…`, a Mollie customer id) and `paymentMethodRef` the vaulted
 * instrument (a Stripe `pm_…`, a Mollie mandate). Without them a gateway can only create an
 * intent with no attached method, which Stripe answers `requires_payment_method` — a status
 * that maps to "needs action" rather than "failed", so dunning never opens and the renewal
 * silently collects NOTHING. That is worse than a decline, because a decline is visible.
 *
 * Both are nullable because this value object also serves ON-SESSION collection, where the
 * customer confirms against an element and the instrument is supplied client-side. A gateway
 * asked to charge off-session without them must fail loudly rather than attempt the charge —
 * see {@see PaymentGateway::charge()}.
 *
 * Carrying the customer/instrument pair is also what lets a gateway claim the PSD2 exemption
 * for a merchant-initiated transaction: the exemption rests on the linkage back to the
 * mandate the customer originally authorised.
 */
readonly class PaymentIntent
{
    public function __construct(
        public string $id,
        public Money $amount,
        public string $reference,
        public ?string $customerRef = null,
        public ?string $paymentMethodRef = null,
    ) {}

    /**
     * Whether this intent carries everything a gateway needs to pull a vaulted instrument
     * with the customer absent.
     */
    public function isOffSession(): bool
    {
        return $this->customerRef !== null && $this->customerRef !== ''
            && $this->paymentMethodRef !== null && $this->paymentMethodRef !== '';
    }
}
