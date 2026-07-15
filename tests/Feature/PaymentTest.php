<?php

declare(strict_types=1);

use Cbox\Billing\Money\Money;
use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Cbox\Billing\Payment\Dunning\DunningPolicy;
use Cbox\Billing\Payment\Enums\PaymentIntentStatus;
use Cbox\Billing\Payment\Enums\PaymentStatus;
use Cbox\Billing\Payment\Gateways\ManualPaymentGateway;
use Cbox\Billing\Payment\Testing\FakePaymentGateway;
use Cbox\Billing\Payment\ValueObjects\PaymentIntent;
use Cbox\Billing\Payment\ValueObjects\PaymentIntentRequest;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;
use Cbox\Billing\Payment\ValueObjects\SetupIntentRequest;

function intent(): PaymentIntent
{
    return new PaymentIntent('pi_1', Money::ofMinor(12500, 'EUR'), 'DK-000001');
}

function paymentIntentRequest(): PaymentIntentRequest
{
    return new PaymentIntentRequest('org_1', 'DK-000001', Money::ofMinor(12500, 'EUR'), 'idem-pi-1');
}

it('defaults to the manual gateway, which records a pending payment', function () {
    $gateway = $this->app->make(PaymentGateway::class);

    $result = $gateway->charge(intent());

    expect($gateway->name())->toBe('manual')
        ->and($result->status)->toBe(PaymentStatus::Pending)
        ->and($result->isSettled())->toBeFalse();
});

it('charges through a gateway and reports settlement', function () {
    $gateway = new FakePaymentGateway(PaymentResult::succeeded('ch_123'));

    $result = $gateway->charge(intent());

    expect($result->isSettled())->toBeTrue()
        ->and($result->gatewayReference)->toBe('ch_123')
        ->and($gateway->charged)->toHaveCount(1);
});

it('reports a failure reason', function () {
    $result = (new FakePaymentGateway(PaymentResult::failed('card_declined')))->charge(intent());

    expect($result->status)->toBe(PaymentStatus::Failed)
        ->and($result->failureReason)->toBe('card_declined');
});

it('creates a payment intent, returning the shaped client-side result', function () {
    $gateway = new FakePaymentGateway(PaymentResult::succeeded('ch_1'));

    $result = $gateway->createPaymentIntent(paymentIntentRequest());

    expect($result->gateway)->toBe('fake')
        ->and($result->publishableKey)->toBe('pub_fake')
        ->and($result->clientSecret)->toBe('cs_pi_idem-pi-1')
        ->and($result->status)->toBe(PaymentIntentStatus::Succeeded)
        ->and($result->reference)->toBe('DK-000001')
        ->and($result->amount?->minor())->toBe(12500)
        ->and($result->requiresCustomerAction())->toBeFalse()
        ->and($gateway->paymentIntents)->toHaveCount(1);
});

it('creates a setup intent for off-session use, returning the shaped result', function () {
    $gateway = new FakePaymentGateway(PaymentResult::succeeded('ch_1'));

    $result = $gateway->createSetupIntent(new SetupIntentRequest('org_1', 'idem-seti-1'));

    expect($result->gateway)->toBe('fake')
        ->and($result->publishableKey)->toBe('pub_fake')
        ->and($result->clientSecret)->toBe('cs_seti_idem-seti-1')
        ->and($result->status)->toBe(PaymentIntentStatus::Succeeded)
        ->and($result->reference)->toBe('seti_idem-seti-1')
        ->and($gateway->setupIntents)->toHaveCount(1);
});

it('flows a requires-action (SCA) status through the created intent', function () {
    $gateway = new FakePaymentGateway(PaymentResult::succeeded('ch_1'), intentStatus: PaymentIntentStatus::RequiresAction);

    $result = $gateway->createPaymentIntent(paymentIntentRequest());

    expect($result->status)->toBe(PaymentIntentStatus::RequiresAction)
        ->and($result->requiresCustomerAction())->toBeTrue()
        ->and($result->clientSecret)->not->toBeNull();
});

it('attaches, lists, and re-defaults payment methods per account', function () {
    $gateway = new FakePaymentGateway(PaymentResult::succeeded('ch_1'));

    $first = $gateway->attachPaymentMethod('org_1', 'pm_a');
    $gateway->attachPaymentMethod('org_1', 'pm_b');

    expect($first->isDefault)->toBeTrue() // first attached is default
        ->and($gateway->paymentMethods('org_1'))->toHaveCount(2)
        ->and($gateway->paymentMethods('org_2'))->toBe([]); // scoped per account

    $gateway->setDefaultPaymentMethod('org_1', 'pm_b');
    $methods = $gateway->paymentMethods('org_1');

    expect($methods[0]->isDefault)->toBeFalse()
        ->and($methods[1]->id)->toBe('pm_b')
        ->and($methods[1]->isDefault)->toBeTrue();
});

it('creates an honest off-line intent on the manual gateway: no element, no client secret', function () {
    $gateway = new ManualPaymentGateway;

    $payment = $gateway->createPaymentIntent(paymentIntentRequest());
    $setup = $gateway->createSetupIntent(new SetupIntentRequest('org_1', 'idem-seti-1'));

    expect($payment->gateway)->toBe('manual')
        ->and($payment->publishableKey)->toBeNull()
        ->and($payment->clientSecret)->toBeNull()
        ->and($payment->status)->toBe(PaymentIntentStatus::Succeeded)
        ->and($payment->amount?->minor())->toBe(12500)
        ->and($setup->clientSecret)->toBeNull()
        ->and($gateway->paymentMethods('org_1'))->toBe([]); // no card vault
});

it('records the manual gateway payment arrangement honestly, without a card', function () {
    $method = (new ManualPaymentGateway)->attachPaymentMethod('org_1', 'offline_1');

    expect($method->brand)->toBe('manual')
        ->and($method->last4)->toBe('')
        ->and($method->expMonth)->toBeNull()
        ->and($method->expYear)->toBeNull()
        ->and($method->isDefault)->toBeTrue();
});

it('schedules dunning retries and exhausts them', function () {
    $policy = new DunningPolicy([1, 3, 5]);

    expect($policy->retryDelayForAttempt(1))->toBe(1)
        ->and($policy->retryDelayForAttempt(3))->toBe(5)
        ->and($policy->retryDelayForAttempt(4))->toBeNull() // exhausted
        ->and($policy->maxRetries())->toBe(3);
});
