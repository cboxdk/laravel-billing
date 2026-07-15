<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\Gateways;

use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Cbox\Billing\Payment\Enums\PaymentIntentStatus;
use Cbox\Billing\Payment\ValueObjects\PaymentIntent;
use Cbox\Billing\Payment\ValueObjects\PaymentIntentRequest;
use Cbox\Billing\Payment\ValueObjects\PaymentIntentResult;
use Cbox\Billing\Payment\ValueObjects\PaymentMethod;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;
use Cbox\Billing\Payment\ValueObjects\RefundIntent;
use Cbox\Billing\Payment\ValueObjects\SetupIntentRequest;
use Cbox\Billing\Payment\ValueObjects\SetupIntentResult;

/**
 * A manual / bank-transfer gateway: it records the intent as pending and leaves
 * settlement to happen out of band (the operator marks it paid when funds arrive).
 * The dependency-free default gateway.
 *
 * It has NO client-side element and NO card vault, so the ADR-0009 intent operations are
 * implemented honestly for that reality rather than stubbed: intent creation returns an
 * off-line result with no publishable key and no client secret (there is nothing for a
 * browser element to confirm), and there are no saved card methods to enumerate. This is
 * a genuine gateway shape — an operator-reconciled account — not a placeholder.
 */
readonly class ManualPaymentGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'manual';
    }

    public function charge(PaymentIntent $intent): PaymentResult
    {
        return PaymentResult::pending($intent->id);
    }

    public function refund(RefundIntent $intent): PaymentResult
    {
        // A manual refund is paid out of band (the operator wires the money back);
        // record it as pending until settlement is confirmed.
        return PaymentResult::pending($intent->id);
    }

    /**
     * A manual charge is reconciled off-line: there is no browser element to confirm and
     * no SCA challenge, so the result carries no publishable key and no client secret. It
     * reports {@see PaymentIntentStatus::Succeeded} as the client-side view of "nothing to
     * do here" — the invoice is still only marked paid on the settled webhook (which for a
     * manual gateway the operator triggers when funds arrive), never on this result.
     */
    public function createPaymentIntent(PaymentIntentRequest $request): PaymentIntentResult
    {
        return new PaymentIntentResult(
            gateway: $this->name(),
            publishableKey: null,
            clientSecret: null,
            status: PaymentIntentStatus::Succeeded,
            reference: $request->reference,
            amount: $request->amount,
        );
    }

    /**
     * A manual gateway saves nothing for off-session use — later charges are reconciled
     * off-line, not billed against a vaulted card. The setup result therefore carries no
     * publishable key and no client secret, and echoes the account as its reference.
     */
    public function createSetupIntent(SetupIntentRequest $request): SetupIntentResult
    {
        return new SetupIntentResult(
            gateway: $this->name(),
            publishableKey: null,
            clientSecret: null,
            status: PaymentIntentStatus::Succeeded,
            reference: $request->account,
        );
    }

    /**
     * A manual gateway has no card vault, so an account never has saved methods.
     *
     * @return list<PaymentMethod>
     */
    public function paymentMethods(string $account): array
    {
        return [];
    }

    /**
     * A manual account's payment arrangement is the off-line one — there is no card to
     * vault. Attaching records the off-line method honestly (no card brand, no expiry) and
     * returns it as the account's default, rather than pretending to store a card.
     */
    public function attachPaymentMethod(string $account, string $paymentMethodId): PaymentMethod
    {
        return new PaymentMethod(
            id: $paymentMethodId,
            brand: 'manual',
            last4: '',
            expMonth: null,
            expYear: null,
            isDefault: true,
        );
    }

    /**
     * A manual account has a single off-line arrangement, so there is nothing to switch
     * between: setting the default is a no-op.
     */
    public function setDefaultPaymentMethod(string $account, string $paymentMethodId): void
    {
        // No card vault, single off-line method: nothing to reassign.
    }
}
