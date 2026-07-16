<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\Contracts;

use Cbox\Billing\Payment\ValueObjects\PaymentIntent;
use Cbox\Billing\Payment\ValueObjects\PaymentIntentRequest;
use Cbox\Billing\Payment\ValueObjects\PaymentIntentResult;
use Cbox\Billing\Payment\ValueObjects\PaymentMethod;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;
use Cbox\Billing\Payment\ValueObjects\RefundIntent;
use Cbox\Billing\Payment\ValueObjects\SetupIntentRequest;
use Cbox\Billing\Payment\ValueObjects\SetupIntentResult;

/**
 * A payment gateway. The engine is gateway-agnostic: a manual/bank-transfer gateway
 * and SDK-backed gateways all implement this. Concrete SDK-backed gateways ship as
 * opt-in adapter packages so the core stays dependency-light.
 *
 * Beyond server-side capture ({@see PaymentGateway::charge()}), the seam exposes the
 * client-side intent operations ADR-0009 needs for embedded integration: creating a
 * PaymentIntent / SetupIntent whose client secret a product's frontend confirms against
 * its own element, and managing the account's saved payment methods. Card data never
 * reaches the engine — Strong Customer Authentication happens on the gateway's element,
 * and a subscription is activated / an invoice marked paid strictly on the gateway's
 * settled webhook, never on a client-side confirmation.
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

    /**
     * Create a PaymentIntent that charges an invoice ON-SESSION. Returns the gateway,
     * publishable key, and client secret a product's frontend mounts its element with to
     * confirm (and complete any SCA challenge) client-side. The request's scoped
     * `idempotencyKey` makes a retried creation collapse to a single gateway intent.
     */
    public function createPaymentIntent(PaymentIntentRequest $request): PaymentIntentResult;

    /**
     * Create a SetupIntent that saves a payment method for OFF-SESSION renewals — no
     * immediate charge. Returns the client secret the frontend confirms the element
     * against. The request's scoped `idempotencyKey` makes a retry collapse to one intent.
     */
    public function createSetupIntent(SetupIntentRequest $request): SetupIntentResult;

    /**
     * Create (or return) the gateway customer object that saved payment methods and
     * off-session charges attach to, and return its gateway reference (e.g. Stripe
     * `cus_…`). `$account` is the host's stable account key — the gateway stamps it into
     * the customer's metadata so the object reconciles back to the account from the
     * gateway dashboard. The host persists the account→reference mapping; this call only
     * mints (or re-resolves) the object at the gateway.
     */
    public function createCustomer(string $account, ?string $email = null, ?string $name = null): string;

    /**
     * The payment methods saved for `$account`, at most one of which is the default the
     * off-session renewal charges.
     *
     * @return list<PaymentMethod>
     */
    public function paymentMethods(string $account): array;

    /**
     * Attach the gateway payment method `$paymentMethodId` to `$account` (the method the
     * gateway vaulted when its element confirmed a SetupIntent) and return it.
     */
    public function attachPaymentMethod(string $account, string $paymentMethodId): PaymentMethod;

    /**
     * Make `$paymentMethodId` the default method future off-session renewals charge for
     * `$account`. The method must already be attached.
     */
    public function setDefaultPaymentMethod(string $account, string $paymentMethodId): void;

    /**
     * Detach the vaulted method `$paymentMethodId` so future off-session renewals can no
     * longer charge it. This is idempotent: detaching an already-detached (or never-known)
     * method is a no-op and must not error, so a retried teardown collapses cleanly. A
     * vault-less gateway treats the whole call as a no-op. Some gateways vault a method
     * globally rather than per-customer and detach it outright, so for them `$account` is
     * advisory / audit-only — the method is removed regardless of which account asks.
     */
    public function detachPaymentMethod(string $account, string $paymentMethodId): void;
}
