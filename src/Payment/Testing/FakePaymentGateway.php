<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\Testing;

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
 * A scripted gateway for tests — returns configured results and records the intents it was
 * asked to charge, refund, and create. When no refund result is scripted it settles the
 * refund successfully, echoing the intent's idempotency key as the gateway reference.
 *
 * The intent operations return a real shaped result with a scriptable
 * {@see PaymentIntentStatus} (default {@see PaymentIntentStatus::Succeeded}; pass
 * {@see PaymentIntentStatus::RequiresAction} to drive an SCA flow), a publishable key, and
 * a client secret derived from the request's idempotency key. Payment methods are stored
 * per account so attach/setDefault/list behave like a small vault the suite can assert on.
 */
class FakePaymentGateway implements PaymentGateway
{
    /** @var list<PaymentIntent> */
    public array $charged = [];

    /** @var list<RefundIntent> */
    public array $refunded = [];

    /** @var list<PaymentIntentRequest> */
    public array $paymentIntents = [];

    /** @var list<SetupIntentRequest> */
    public array $setupIntents = [];

    /** @var array<string, list<PaymentMethod>> */
    private array $methods = [];

    public function __construct(
        private PaymentResult $result,
        private ?PaymentResult $refundResult = null,
        private PaymentIntentStatus $intentStatus = PaymentIntentStatus::Succeeded,
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

    public function createPaymentIntent(PaymentIntentRequest $request): PaymentIntentResult
    {
        $this->paymentIntents[] = $request;

        return new PaymentIntentResult(
            gateway: $this->name(),
            publishableKey: 'pub_fake',
            clientSecret: 'cs_pi_'.$request->idempotencyKey,
            status: $this->intentStatus,
            reference: $request->reference,
            amount: $request->amount,
        );
    }

    public function createSetupIntent(SetupIntentRequest $request): SetupIntentResult
    {
        $this->setupIntents[] = $request;

        return new SetupIntentResult(
            gateway: $this->name(),
            publishableKey: 'pub_fake',
            clientSecret: 'cs_seti_'.$request->idempotencyKey,
            status: $this->intentStatus,
            reference: 'seti_'.$request->idempotencyKey,
        );
    }

    /**
     * @return list<PaymentMethod>
     */
    public function paymentMethods(string $account): array
    {
        return $this->methods[$account] ?? [];
    }

    public function attachPaymentMethod(string $account, string $paymentMethodId): PaymentMethod
    {
        // The first method attached to an account becomes its default.
        $isDefault = ($this->methods[$account] ?? []) === [];

        $method = new PaymentMethod(
            id: $paymentMethodId,
            brand: 'visa',
            last4: '4242',
            expMonth: 12,
            expYear: 2030,
            isDefault: $isDefault,
        );

        $this->methods[$account][] = $method;

        return $method;
    }

    public function setDefaultPaymentMethod(string $account, string $paymentMethodId): void
    {
        $this->methods[$account] = array_map(
            static fn (PaymentMethod $method): PaymentMethod => new PaymentMethod(
                id: $method->id,
                brand: $method->brand,
                last4: $method->last4,
                expMonth: $method->expMonth,
                expYear: $method->expYear,
                isDefault: $method->id === $paymentMethodId,
            ),
            $this->methods[$account] ?? [],
        );
    }
}
