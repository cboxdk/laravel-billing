<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\Dunning;

use Cbox\Billing\Account\Enums\AccountStandingState;
use Cbox\Billing\Payment\Dunning\Contracts\DelinquencyPolicy;
use Cbox\Billing\Payment\Dunning\ValueObjects\DelinquentInvoice;
use Cbox\Billing\Payment\Dunning\ValueObjects\DunningConfig;
use Cbox\Billing\Payment\Dunning\ValueObjects\DunningOutcome;
use Cbox\Billing\Payment\Dunning\ValueObjects\DunningSnapshot;
use DateTimeImmutable;

/**
 * The default delinquency decision — a pure function over the account snapshot, the
 * knob set, and the current instant. It reads nothing and writes nothing; it names the
 * one action to take. The knobs map onto it directly:
 *
 *  - The bypass allow-list short-circuits everything: an allow-listed account is never
 *    dunned.
 *  - `graceHours` filters the outstanding set — an invoice inside the grace window is
 *    not yet dunnable, so a just-missed payment is ignored.
 *  - `minNoticeCount` gates escalation: an account is never suspended until at least
 *    that many reminders have gone out, even past the day threshold.
 *  - `maxDelinquencyDays` is the escalation threshold: once the oldest dunnable invoice
 *    is that old (and the reminders have been sent), the account is suspended.
 *  - `noticeFrequencyDays` paces the reminders between those points.
 *
 * The decision only ever *names* a standing change ({@see AccountStandingState}); it is
 * the runner that flips standing. It never reads or writes credit balances — access and
 * money are separate seams.
 *
 * Standing drives which actions are even possible:
 *  - Disputed — a chargeback owns that gate; dunning defers and does nothing.
 *  - Suspended — the only move is Restore, and only once ALL debt is cleared and none
 *    is written off; otherwise it stays suspended.
 *  - Good — the normal dun/escalate path.
 */
readonly class DefaultDelinquencyPolicy implements DelinquencyPolicy
{
    public function decide(DunningSnapshot $snapshot, DunningConfig $config, DateTimeImmutable $now): DunningOutcome
    {
        if ($snapshot->bypassed) {
            return DunningOutcome::noOp('bypass: account is on the delinquent allow-list');
        }

        return match ($snapshot->standing) {
            AccountStandingState::Disputed => DunningOutcome::noOp('deferred: an open dispute owns the access gate'),
            AccountStandingState::Suspended => $this->decideWhileSuspended($snapshot),
            AccountStandingState::Good => $this->decideWhileGood($snapshot, $config, $now),
        };
    }

    /**
     * A suspended account: the only forward move is to lift the gate, and only when the
     * debt is genuinely gone. Clearing *some* invoices does not restore access while any
     * open invoice remains, and written-off (uncollectible) debt blocks restore outright
     * — so paying part of the bill never silently reopens the door.
     */
    private function decideWhileSuspended(DunningSnapshot $snapshot): DunningOutcome
    {
        if ($snapshot->hasUncollectible()) {
            return DunningOutcome::noOp('held: written-off debt blocks restore');
        }

        if ($snapshot->hasOpenInvoice()) {
            return DunningOutcome::noOp('held: open debt remains outstanding');
        }

        return DunningOutcome::restore('debt cleared and none written off; access restored');
    }

    /**
     * An account in good standing: chase the outstanding (past-grace) invoices, escalate
     * to suspension once old enough AND sufficiently warned, otherwise pace reminders.
     */
    private function decideWhileGood(DunningSnapshot $snapshot, DunningConfig $config, DateTimeImmutable $now): DunningOutcome
    {
        $outstanding = $this->dunnable($snapshot, $config, $now);

        if ($outstanding === []) {
            return DunningOutcome::noOp('no outstanding invoices past the grace window');
        }

        $maxDays = $this->maxDaysPastDue($outstanding, $now);
        $numbers = $this->numbersOf($outstanding);

        // Escalate only when BOTH the day threshold is reached and the minimum reminders
        // have been sent — min-notice-count means an account is never suspended un-warned.
        if ($maxDays >= $config->maxDelinquencyDays && $snapshot->state->noticesSent >= $config->minNoticeCount) {
            return DunningOutcome::suspend(
                $numbers,
                $maxDays,
                sprintf('%d days delinquent after %d notice(s); suspending', $maxDays, $snapshot->state->noticesSent),
            );
        }

        if ($snapshot->state->noticeDue($now, $config)) {
            $noticeNumber = $snapshot->state->noticesSent + 1;

            return DunningOutcome::sendNotice(
                $numbers,
                $noticeNumber,
                $maxDays,
                sprintf('notice %d of at least %d; %d days delinquent', $noticeNumber, $config->minNoticeCount, $maxDays),
            );
        }

        return DunningOutcome::noOp('waiting for the notice cadence');
    }

    /**
     * The open invoices that are past due beyond the grace window — the set dunning acts
     * on. Invoices inside the window (a just-missed payment) are excluded.
     *
     * @return list<DelinquentInvoice>
     */
    private function dunnable(DunningSnapshot $snapshot, DunningConfig $config, DateTimeImmutable $now): array
    {
        $dunnable = [];

        foreach ($snapshot->invoices as $invoice) {
            if ($invoice->isDunnable($now, $config)) {
                $dunnable[] = $invoice;
            }
        }

        return $dunnable;
    }

    /**
     * @param  list<DelinquentInvoice>  $invoices
     */
    private function maxDaysPastDue(array $invoices, DateTimeImmutable $now): int
    {
        $max = 0;

        foreach ($invoices as $invoice) {
            $days = $invoice->daysPastDue($now);
            if ($days > $max) {
                $max = $days;
            }
        }

        return $max;
    }

    /**
     * @param  list<DelinquentInvoice>  $invoices
     * @return list<string>
     */
    private function numbersOf(array $invoices): array
    {
        $numbers = [];

        foreach ($invoices as $invoice) {
            $numbers[] = $invoice->number;
        }

        return $numbers;
    }
}
