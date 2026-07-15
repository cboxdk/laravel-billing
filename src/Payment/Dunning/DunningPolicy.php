<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\Dunning;

/**
 * The retry schedule for a failed payment: the delay (in days) before each
 * successive attempt. When the attempts are exhausted, `retryDelayForAttempt`
 * returns null and the caller escalates (suspend, write off).
 */
readonly class DunningPolicy
{
    /** @var list<int> */
    public array $delaysInDays;

    /**
     * @param  list<int>  $delaysInDays  Delay before attempt 1, 2, 3, … after the initial failure.
     */
    public function __construct(array $delaysInDays = [1, 3, 5])
    {
        $this->delaysInDays = $delaysInDays;
    }

    /** Delay (days) before the given 1-based retry attempt, or null when exhausted. */
    public function retryDelayForAttempt(int $attempt): ?int
    {
        return $this->delaysInDays[$attempt - 1] ?? null;
    }

    public function maxRetries(): int
    {
        return count($this->delaysInDays);
    }
}
