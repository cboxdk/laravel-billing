<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\Dunning\Enums;

/**
 * The single action a dunning decision resolves to for one account on one run:
 *
 *  - NoOp       — nothing to do (within grace, waiting on the notice cadence, already
 *                 gated, or held below the access line by written-off debt).
 *  - SendNotice — send the next reminder in the sequence.
 *  - Suspend    — escalate: gate the account's access (flip its standing).
 *  - Restore    — the debt is cleared and none is written off; lift the gate.
 *
 * The decision is pure — it names the action; a thin runner is what actually applies a
 * standing change or sends the notice.
 */
enum DunningAction: string
{
    case NoOp = 'no_op';
    case SendNotice = 'send_notice';
    case Suspend = 'suspend';
    case Restore = 'restore';
}
