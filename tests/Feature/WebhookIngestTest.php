<?php

declare(strict_types=1);

use Cbox\Billing\Money\Money;
use Cbox\Billing\Payment\Contracts\WebhookVerifier;
use Cbox\Billing\Payment\Enums\WebhookEventType;
use Cbox\Billing\Payment\Enums\WebhookIngestStatus;
use Cbox\Billing\Payment\Exceptions\WebhookVerificationFailed;
use Cbox\Billing\Payment\Testing\FakeWebhookVerifier;
use Cbox\Billing\Payment\ValueObjects\WebhookEvent;
use Cbox\Billing\Payment\ValueObjects\WebhookPayload;

function settledEvent(string $id = 'evt_1', string $reference = 'DK-000001'): WebhookEvent
{
    return new WebhookEvent($id, WebhookEventType::PaymentSettled, $reference, Money::ofMinor(12500, 'EUR'));
}

it('rejects an unverified webhook: the default engine trusts nothing', function () {
    $verifier = $this->app->make(WebhookVerifier::class);

    expect(fn () => $verifier->verify(new WebhookPayload('{}', ['signature' => 'nope'])))
        ->toThrow(WebhookVerificationFailed::class);
});

it('rejects a payload an adapter cannot verify (deny-by-default)', function () {
    $verifier = FakeWebhookVerifier::rejecting();

    expect(fn () => $verifier->verify(new WebhookPayload('{"forged":true}')))
        ->toThrow(WebhookVerificationFailed::class);
});

it('applies a verified settlement to the invoice exactly once', function () {
    $event = settledEvent();

    $outcome = $this->webhookIngest()->ingest($event);

    expect($outcome->status)->toBe(WebhookIngestStatus::Applied)
        ->and($outcome->wasApplied())->toBeTrue()
        ->and($this->webhookApplier()->timesPaid('DK-000001'))->toBe(1)
        ->and($this->webhookApplier()->amountPaid('DK-000001')->minor())->toBe(12500)
        ->and($this->webhookSettled()->isSettled('DK-000001'))->toBeTrue();
});

it('collapses a re-delivered event id to a no-op', function () {
    $event = settledEvent();

    $this->webhookIngest()->ingest($event);
    $second = $this->webhookIngest()->ingest($event); // same event id, re-delivered

    expect($second->status)->toBe(WebhookIngestStatus::AlreadySettled)
        ->and($this->webhookApplier()->timesPaid('DK-000001'))->toBe(1);
});

it('settles a reference exactly once even across two distinct events', function () {
    // A gateway can emit two different events that both mean "this invoice is paid".
    $first = settledEvent('evt_a', 'DK-000001');
    $second = settledEvent('evt_b', 'DK-000001');

    $this->webhookIngest()->ingest($first);
    $outcome = $this->webhookIngest()->ingest($second);

    expect($outcome->status)->toBe(WebhookIngestStatus::AlreadySettled)
        ->and($this->webhookApplier()->timesPaid('DK-000001'))->toBe(1)
        ->and($this->webhookSettled()->settledCount())->toBe(1);
});

it('still applies exactly once when the host crashes before persisting, then re-delivers', function () {
    $event = settledEvent();

    // First delivery: the host dies mid-persist. Nothing is recorded — as a rolled-back
    // transaction leaves it — so the settle claim and the event id are NOT burned.
    $this->webhookApplier()->crashOnNextApply();
    expect(fn () => $this->webhookIngest()->ingest($event))->toThrow(RuntimeException::class);

    expect($this->webhookSettled()->isSettled('DK-000001'))->toBeFalse()
        ->and($this->webhookProcessed()->hasSeen('evt_1'))->toBeFalse()
        ->and($this->webhookApplier()->timesPaid('DK-000001'))->toBe(0);

    // The gateway re-delivers. This time it applies — exactly once, not twice.
    $outcome = $this->webhookIngest()->ingest($event);

    expect($outcome->status)->toBe(WebhookIngestStatus::Applied)
        ->and($this->webhookApplier()->timesPaid('DK-000001'))->toBe(1);
});

it('records but does not apply a non-settlement event, and dedups its re-delivery', function () {
    $failed = new WebhookEvent('evt_f', WebhookEventType::PaymentFailed, 'DK-000002', Money::ofMinor(500, 'EUR'));

    $first = $this->webhookIngest()->ingest($failed);
    $second = $this->webhookIngest()->ingest($failed);

    expect($first->status)->toBe(WebhookIngestStatus::Ignored)
        ->and($second->status)->toBe(WebhookIngestStatus::DuplicateEvent)
        ->and($this->webhookApplier()->isPaid('DK-000002'))->toBeFalse();
});

it('accepts and ingests through a scripted adapter verifier end to end', function () {
    $event = settledEvent('evt_wire', 'DK-000009');
    $verifier = FakeWebhookVerifier::accepting($event);

    $verified = $verifier->verify(new WebhookPayload('{"raw":"bytes"}', ['signature' => 'good']));
    $outcome = $this->webhookIngest()->ingest($verified);

    expect($verified->reference)->toBe('DK-000009')
        ->and($verified->toPaymentResult()->isSettled())->toBeTrue()
        ->and($outcome->wasApplied())->toBeTrue()
        ->and($this->webhookApplier()->timesPaid('DK-000009'))->toBe(1)
        ->and($verifier->verified)->toHaveCount(1);
});
