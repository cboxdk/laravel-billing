<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\Retirement\Enums;

use Cbox\Billing\Subscription\Retirement\RetirementResolution;

/**
 * The six mutually-exclusive outcomes of resolving a subscription against its plan's
 * retirement (ADR-0016), tagging a {@see RetirementResolution}:
 *
 *  - `NotRetiring`          — the plan is not being sunset, or its cutoff has not yet
 *                             been reached at this renewal: renew normally.
 *  - `RetiringChooseBy`     — the plan is retired but this subscriber still has paid time
 *                             left: they must choose (successor / cancel) by the carried
 *                             renewal-due date. Not enacted yet.
 *  - `ResolvedToSuccessor`  — the subscriber scheduled a successor plan: migrate to it.
 *  - `ResolvedToCancel`     — the subscriber scheduled a cancel: cancel at the renewal.
 *  - `ResolvedToDefault`    — no choice, but the retirement configures a default
 *                             successor: migrate to it.
 *  - `UnresolvedRetirement` — retired, no choice, no default: **deny-by-default**. The
 *                             renewal must NOT silently continue on the retired plan; the
 *                             host surfaces this instead.
 */
enum RetirementOutcome: string
{
    case NotRetiring = 'not-retiring';
    case RetiringChooseBy = 'retiring-choose-by';
    case ResolvedToSuccessor = 'resolved-to-successor';
    case ResolvedToCancel = 'resolved-to-cancel';
    case ResolvedToDefault = 'resolved-to-default';
    case UnresolvedRetirement = 'unresolved-retirement';
}
