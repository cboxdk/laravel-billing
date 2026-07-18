---
title: Cancellation & retention
description: The survey + save-offer seam and its events — the app binds a basic default, a plugin binds the rich flow.
weight: 66
---

# Cancellation & retention

When a subscriber cancels, some hosts want to ask *why* and offer them a reason to stay. The
rich flow — a per-merchant survey, targeted save-offers, eligibility caps, win-back automation
— lives in the private `cbox-billing-retention` plugin, not the engine. But a plugin cannot
depend on the app, so the **contract seam** it builds on has to live here in the engine (the
same reasoning as the shared capability gate). This page is that seam.

The engine ships only the seam and inert defaults. **The app binds a basic default; a plugin
binds the rich flow.** With neither, the seam surfaces nothing and a cancel is a plain cancel —
the engine forces no survey or offer onto a host that does not want one.

## The two contracts

Both live under `Cbox\Billing\Retention\Contracts` and are bound with `bindIf`, so the first
binder wins:

| Contract | Method | Returns |
| --- | --- | --- |
| `CancellationSurvey` | `reasonsFor(string $account, string $subscriptionId)` | `list<CancellationReason>` — the reasons offered at cancel |
| `RetentionOffers` | `offersFor(string $account, string $subscriptionId)` | `list<SaveOffer>` — the save-offers to present |

The engine binds `NullCancellationSurvey` and `NullRetentionOffers` — both return an empty list.
An empty survey means "no survey"; empty offers mean "present nothing". That is the inert
default: a plain cancel.

```php
// The app's basic default, or the plugin's rich flow — bindIf lets the first binder win.
$this->app->bind(CancellationSurvey::class, MyMerchantSurvey::class);
$this->app->bind(RetentionOffers::class, MyMerchantOffers::class);
```

## The value objects

- **`CancellationReason`** (`string $key`, `string $label`, `bool $requiresComment = false`) —
  one merchant-configured reason. `requiresComment` marks a reason (e.g. an "other" bucket)
  that only makes sense with free text.
- **`CancellationResponse`** (`?string $reasonKey`, `?string $comment`) — what the subscriber
  answered. Both nullable; a plain cancel carries no response.
- **`SaveOffer`** (`SaveOfferType $type`, `string $key`, `string $label`, + typed params) — one
  offer. Build it through a named constructor so it can never carry the wrong params for its
  type; the constructor validates the invariants deny-by-default.

`SaveOfferType` maps each offer to a lever the engine already owns, so an accepted offer is
enacted through an existing service rather than a bespoke path:

| Type | Named constructor | Typed params | Lever |
| --- | --- | --- | --- |
| `FreeMonth` | `SaveOffer::freeMonth($key, $label, $freeMonths = 1)` | `freeMonths` | credit grant / free period |
| `Discount` | `SaveOffer::discount($key, $label, $percent, $durationCycles)` | `discountPercent`, `durationCycles` | coupon |
| `Pause` | `SaveOffer::pause($key, $label, $pauseCycles)` | `pauseCycles` | subscription pause |
| `Downgrade` | `SaveOffer::downgrade($key, $label, $targetProductId, $targetPriceId)` | `targetProductId`, `targetPriceId` | plan change |
| `Custom` | `SaveOffer::custom($key, $label)` | — | host-handled (e.g. "offer a call") |

`RetentionOutcome` — `Canceled`, `SavedByOffer`, `Deferred` — records how a request ultimately
settled, for churn / save-rate reporting.

## The events

The `RetentionRecorder` is the thin service the host calls at its cancel path to emit the
retention events. It emits and nothing else — it does not itself cancel, pause, discount, or
grant; the host decides *when* to call it and enacts an accepted offer through the real levers.
Its dispatcher is an optional, trailing dependency (the same no-BC pattern the subscription
manager uses), so a recorder built without one simply emits nothing.

| Event | Recorder call | Payload |
| --- | --- | --- |
| `Events\SubscriptionCancellationRequested` | `cancellationRequested($subscription, $account, $response)` | `Subscription`, `string $account`, `?CancellationResponse` |
| `Events\SaveOfferAccepted` | `offerAccepted($subscription, $offer)` | `Subscription`, `SaveOffer` |
| `Events\RetentionResolved` | `resolved($subscription, $outcome, $response)` | `Subscription`, `RetentionOutcome`, `?CancellationResponse` |

`SubscriptionCancellationRequested` fires before any state change, so a listener can react while
the subscription still serves. None of these mutate the subscription — the
`SubscriptionManager` cancel transitions are unchanged; these are signals a plugin listens for,
not commands.

```php
// The host's cancel path — emit, then proceed with the engine's unchanged cancel.
$reasons = app(CancellationSurvey::class)->reasonsFor($account, $sub->id);
$offers = app(RetentionOffers::class)->offersFor($account, $sub->id);
// … present them; collect the subscriber's response …

$recorder = app(RetentionRecorder::class);
$recorder->cancellationRequested($sub, $account, $response);
// … if they took an offer: $recorder->offerAccepted($sub, $offer); enact via the mapped lever …
// … otherwise: $manager->cancelNow($sub); …
$recorder->resolved($sub, $outcome, $response);
```

## Testing

`Cbox\Billing\Retention\Testing` ships the seams the engine's own suite uses:

- `FakeCancellationSurvey` / `FakeRetentionOffers` — configurable stand-ins for the app or
  plugin binding (`->offer(...)`, `->present(...)`).
- `InteractsWithRetention` — the trait wiring the fakes and a `RetentionRecorder` over the
  container dispatcher.

```php
$survey = $this->fakeCancellationSurvey(new CancellationReason('too_expensive', 'Too expensive'));
$offers = $this->fakeRetentionOffers(SaveOffer::discount('save_25', '25% off', 25, 3));
$this->retentionRecorder()->cancellationRequested($sub, 'acme', $response);
```
