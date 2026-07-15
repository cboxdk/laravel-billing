<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\Dunning\ValueObjects;

use Cbox\Billing\Payment\Dunning\Exceptions\InvalidDunningConfig;

/**
 * The full operational knob set for delinquency handling. Immutable and validated on
 * construction (deny-by-default): a meaningless value refuses rather than silently
 * disabling escalation.
 *
 *  - `maxDelinquencyDays`  — once the oldest past-due invoice is this many days old,
 *                            the account is escalated to suspension.
 *  - `minNoticeCount`      — how many reminders must go out BEFORE suspension is
 *                            allowed. Even past the day threshold, the account keeps
 *                            receiving notices until this many have been sent — so an
 *                            account is never suspended un-warned.
 *  - `noticeFrequencyDays` — the minimum gap between two reminders (the cadence).
 *  - `graceHours`          — a just-missed payment is not dunned: an invoice less than
 *                            this many hours past its due instant is ignored (default
 *                            24h). This governs *notices/suspension* only, not restore —
 *                            any open invoice, however fresh, still counts as debt that
 *                            keeps a suspended account suspended.
 */
readonly class DunningConfig
{
    public function __construct(
        public int $maxDelinquencyDays,
        public int $minNoticeCount,
        public int $noticeFrequencyDays,
        public int $graceHours = 24,
    ) {
        if ($maxDelinquencyDays < 1) {
            throw InvalidDunningConfig::nonPositive('max_delinquency_days', $maxDelinquencyDays);
        }

        if ($noticeFrequencyDays < 1) {
            throw InvalidDunningConfig::nonPositive('notice_frequency_days', $noticeFrequencyDays);
        }

        if ($minNoticeCount < 0) {
            throw InvalidDunningConfig::negative('min_notice_count', $minNoticeCount);
        }

        if ($graceHours < 0) {
            throw InvalidDunningConfig::negative('grace_hours', $graceHours);
        }
    }

    /**
     * Build from the `billing.payment.dunning` config array. Missing keys fall back to
     * the shipped defaults (30-day suspend threshold, 3 reminders, weekly cadence,
     * 24h grace).
     *
     * @param  array<array-key, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            maxDelinquencyDays: self::intOr($config, 'max_delinquency_days', 30),
            minNoticeCount: self::intOr($config, 'min_notice_count', 3),
            noticeFrequencyDays: self::intOr($config, 'notice_frequency_days', 7),
            graceHours: self::intOr($config, 'grace_hours', 24),
        );
    }

    /** Grace window expressed in seconds, for comparison against a past-due duration. */
    public function graceSeconds(): int
    {
        return $this->graceHours * 3600;
    }

    /** Notice cadence expressed in seconds, for comparison against time-since-last-notice. */
    public function noticeFrequencySeconds(): int
    {
        return $this->noticeFrequencyDays * 86400;
    }

    /**
     * @param  array<array-key, mixed>  $config
     */
    private static function intOr(array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? $default;

        return is_numeric($value) ? (int) $value : $default;
    }
}
