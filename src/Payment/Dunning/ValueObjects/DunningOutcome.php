<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\Dunning\ValueObjects;

use Cbox\Billing\Account\Enums\AccountStandingState;
use Cbox\Billing\Payment\Dunning\Enums\DunningAction;

/**
 * The result of a dunning decision for one account: the single {@see DunningAction} to
 * take, a human-readable reason (for the audit trail / operator visibility), and the
 * data the runner needs to act — which invoices are involved, which reminder in the
 * sequence this is, how delinquent the account is, and the standing to move to.
 *
 * Built through the named constructors, never directly, so an outcome is always
 * internally consistent (e.g. a suspend always carries the target standing).
 */
readonly class DunningOutcome
{
    /**
     * @param  list<string>  $invoiceNumbers
     */
    private function __construct(
        public DunningAction $action,
        public string $reason,
        public array $invoiceNumbers = [],
        public ?int $noticeNumber = null,
        public ?int $delinquencyDays = null,
        public ?AccountStandingState $newStanding = null,
    ) {}

    public static function noOp(string $reason): self
    {
        return new self(DunningAction::NoOp, $reason);
    }

    /**
     * @param  list<string>  $invoiceNumbers
     */
    public static function sendNotice(array $invoiceNumbers, int $noticeNumber, int $delinquencyDays, string $reason): self
    {
        return new self(
            action: DunningAction::SendNotice,
            reason: $reason,
            invoiceNumbers: $invoiceNumbers,
            noticeNumber: $noticeNumber,
            delinquencyDays: $delinquencyDays,
        );
    }

    /**
     * @param  list<string>  $invoiceNumbers
     */
    public static function suspend(array $invoiceNumbers, int $delinquencyDays, string $reason): self
    {
        return new self(
            action: DunningAction::Suspend,
            reason: $reason,
            invoiceNumbers: $invoiceNumbers,
            delinquencyDays: $delinquencyDays,
            newStanding: AccountStandingState::Suspended,
        );
    }

    public static function restore(string $reason): self
    {
        return new self(
            action: DunningAction::Restore,
            reason: $reason,
            newStanding: AccountStandingState::Good,
        );
    }

    public function is(DunningAction $action): bool
    {
        return $this->action === $action;
    }
}
