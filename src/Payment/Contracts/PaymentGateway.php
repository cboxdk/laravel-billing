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
}
