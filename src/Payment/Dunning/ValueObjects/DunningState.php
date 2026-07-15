<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\Dunning\ValueObjects;

use DateTimeImmutable;

/**
 * The per-account progress of a dunning sequence: how many reminders have gone out and
 * when the last one did. It is the memory the cadence and the minimum-notice gate read
 * — the decision is pure, so this travels in with the snapshot rather than being a
 * side channel. Immutable; advancing it returns a new instance.
 */
readonly class DunningState
{
    public function __construct(
        public int $noticesSent = 0,
        public ?DateTimeImmutable $lastNoticeAt = null,
    ) {}

    /** A clean slate — no reminders sent yet. */
    public static function fresh(): self
    {
        return new self;
    }

    /** Record that the next reminder went out at `$at`. */
    public function withNoticeSent(DateTimeImmutable $at): self
    {
        return new self($this->noticesSent + 1, $at);
    }

    /**
     * Whether a reminder is due now: the very first one is always due; a later one only
     * once the cadence gap has elapsed since the last.
     */
    public function noticeDue(DateTimeImmutable $now, DunningConfig $config): bool
    {
        if ($this->lastNoticeAt === null) {
            return true;
        }

        return ($now->getTimestamp() - $this->lastNoticeAt->getTimestamp()) >= $config->noticeFrequencySeconds();
    }
}
