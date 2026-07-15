<?php

declare(strict_types=1);

use Cbox\Billing\Money\Money;
use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Cbox\Billing\Payment\Dunning\DunningPolicy;
use Cbox\Billing\Payment\Enums\PaymentStatus;
use Cbox\Billing\Payment\Testing\FakePaymentGateway;
use Cbox\Billing\Payment\ValueObjects\PaymentIntent;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;

function intent(): PaymentIntent
{
    return new PaymentIntent('pi_1', Money::ofMinor(12500, 'EUR'), 'DK-000001');
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

it('schedules dunning retries and exhausts them', function () {
    $policy = new DunningPolicy([1, 3, 5]);

    expect($policy->retryDelayForAttempt(1))->toBe(1)
        ->and($policy->retryDelayForAttempt(3))->toBe(5)
        ->and($policy->retryDelayForAttempt(4))->toBeNull() // exhausted
        ->and($policy->maxRetries())->toBe(3);
});
