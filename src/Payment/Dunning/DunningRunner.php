<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\Dunning;

use Cbox\Billing\Account\Contracts\AccountStanding;
use Cbox\Billing\Account\Enums\AccountStandingState;
use Cbox\Billing\Payment\Dunning\Contracts\DelinquencyPolicy;
use Cbox\Billing\Payment\Dunning\Contracts\DelinquentAllowList;
use Cbox\Billing\Payment\Dunning\Contracts\DunningStateStore;
use Cbox\Billing\Payment\Dunning\Enums\DunningAction;
use Cbox\Billing\Payment\Dunning\ValueObjects\DelinquentInvoice;
use Cbox\Billing\Payment\Dunning\ValueObjects\DunningConfig;
use Cbox\Billing\Payment\Dunning\ValueObjects\DunningOutcome;
use Cbox\Billing\Payment\Dunning\ValueObjects\DunningSnapshot;
use Cbox\Billing\Payment\Dunning\ValueObjects\DunningState;
use DateTimeImmutable;
use LogicException;

/**
 * The thin runner that applies a dunning decision. It assembles the snapshot from the
 * stores (current standing, notice progress, bypass flag), asks the {@see
 * DelinquencyPolicy} for the outcome, and applies it:
 *
 *  - SendNotice — advance and persist the notice progress (the caller sends the actual
 *    message off the returned outcome).
 *  - Suspend    — flip the account's {@see AccountStanding} to Suspended.
 *  - Restore    — flip standing back to Good and reset the notice progress.
 *  - NoOp       — leave everything untouched.
 *
 * Freeze/suspend gates ACCESS and only access: the runner talks to the standing store
 * and the dunning-state store, and to NOTHING else. It has no ledger and no wallet
 * dependency by construction, so a suspension can never touch a credit balance or the
 * ledger — access is withheld (standing no longer {@see AccountStandingState::grantsAccess()})
 * while the account's credits sit exactly where they were.
 */
readonly class DunningRunner
{
    public function __construct(
        private DelinquencyPolicy $policy,
        private AccountStanding $standing,
        private DunningStateStore $stateStore,
        private DelinquentAllowList $allowList,
        private DunningConfig $config,
    ) {}

    /**
     * Evaluate and apply dunning for one account against its current invoices at `$now`.
     *
     * @param  list<DelinquentInvoice>  $invoices
     */
    public function run(string $account, array $invoices, DateTimeImmutable $now): DunningOutcome
    {
        $snapshot = new DunningSnapshot(
            account: $account,
            invoices: $invoices,
            standing: $this->standing->standingOf($account),
            state: $this->stateStore->load($account),
            bypassed: $this->allowList->allows($account),
        );

        $outcome = $this->policy->decide($snapshot, $this->config, $now);

        $this->apply($account, $snapshot, $outcome, $now);

        return $outcome;
    }

    private function apply(string $account, DunningSnapshot $snapshot, DunningOutcome $outcome, DateTimeImmutable $now): void
    {
        switch ($outcome->action) {
            case DunningAction::SendNotice:
                $this->stateStore->save($account, $snapshot->state->withNoticeSent($now));

                return;

            case DunningAction::Suspend:
                // Gates access only — flips standing, never touches credits or the ledger.
                $this->standing->flag($account, $this->requireStanding($outcome), $outcome->reason);

                return;

            case DunningAction::Restore:
                $this->standing->flag($account, $this->requireStanding($outcome), $outcome->reason);
                $this->stateStore->save($account, DunningState::fresh());

                return;

            case DunningAction::NoOp:
                return;
        }
    }

    private function requireStanding(DunningOutcome $outcome): AccountStandingState
    {
        // The named constructors guarantee a Suspend/Restore outcome carries a standing;
        // this is the deny-by-default guard proving it rather than assuming it.
        return $outcome->newStanding ?? throw new LogicException(
            sprintf('Dunning action [%s] reached the runner without a target standing.', $outcome->action->value),
        );
    }
}
