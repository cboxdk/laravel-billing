<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\ValueObjects;

use Cbox\Billing\Subscription\Enums\BillingInterval;
use Cbox\Billing\Wallet\Enums\GrantCadence;
use InvalidArgumentException;
use LogicException;

/**
 * How often a price bills: every `count` of an {@see BillingInterval} unit. Every month
 * is `Monthly × 1`, a quarter `Monthly × 3`, half a year `Monthly × 6`, a fortnight
 * `Weekly × 2`.
 *
 * Equal spans fold onto one value, so the same cadence is never two prices: 12 months is
 * a year, 7 days a week. The fold is what {@see key()} names, and the key is the durable
 * string a host stores: `month`, `year`, `week`, `day` for a count of one, and
 * `{count}-{unit}` otherwise (`3-month`, `2-week`). {@see fromKey()} also reads the
 * common spellings (`monthly`, `quarterly`, `semi_annual`, ...).
 *
 * A cycle spans at most three years. Longer contracts are a commitment term, not a
 * billing cadence.
 */
final readonly class BillingFrequency
{
    /** The longest cycle, in months; day-based cycles are capped at the same span in days. */
    public const int MAX_MONTHS = 36;

    public const int MAX_DAYS = 1095;

    /**
     * Days per month on the Gregorian average: 146097 days in 400 years of 4800 months.
     * Used only to compare and normalise, never to place a boundary.
     */
    private const int GREGORIAN_DAYS = 146097;

    private const int GREGORIAN_MONTHS = 4800;

    private const array ALIASES = [
        'month' => 'month', 'monthly' => 'month',
        'year' => 'year', 'yearly' => 'year', 'annual' => 'year', 'annually' => 'year',
        'week' => 'week', 'weekly' => 'week',
        'day' => 'day', 'daily' => 'day',
        'quarter' => '3-month', 'quarterly' => '3-month',
        'semi_annual' => '6-month', 'semi-annual' => '6-month', 'semiannual' => '6-month',
        'semi_annually' => '6-month', 'semi-annually' => '6-month', 'semiannually' => '6-month',
        'half_year' => '6-month', 'half-year' => '6-month', 'half_yearly' => '6-month', 'half-yearly' => '6-month',
    ];

    private const array UNITS = [
        'day' => BillingInterval::Daily,
        'week' => BillingInterval::Weekly,
        'month' => BillingInterval::Monthly,
        'year' => BillingInterval::Yearly,
    ];

    public BillingInterval $interval;

    public int $count;

    /**
     * @throws InvalidArgumentException for a count below one or a span past three years
     */
    public function __construct(BillingInterval $interval, int $count = 1)
    {
        if ($count < 1) {
            throw new InvalidArgumentException("A billing interval count must be at least 1, got {$count}.");
        }

        // Fold equal spans: 12 months is a year, 7 days a week.
        if ($interval === BillingInterval::Monthly && $count % 12 === 0) {
            [$interval, $count] = [BillingInterval::Yearly, intdiv($count, 12)];
        } elseif ($interval === BillingInterval::Daily && $count % 7 === 0) {
            [$interval, $count] = [BillingInterval::Weekly, intdiv($count, 7)];
        }

        $tooLong = $interval->isDayBased()
            ? $count * $interval->days() > self::MAX_DAYS
            : $count * $interval->months() > self::MAX_MONTHS;

        if ($tooLong) {
            throw new InvalidArgumentException("A billing cycle spans at most three years, got {$count} × {$interval->value}.");
        }

        $this->interval = $interval;
        $this->count = $count;
    }

    public static function of(BillingInterval $interval, int $count = 1): self
    {
        return new self($interval, $count);
    }

    public static function monthly(): self
    {
        return new self(BillingInterval::Monthly);
    }

    public static function yearly(): self
    {
        return new self(BillingInterval::Yearly);
    }

    public static function quarterly(): self
    {
        return new self(BillingInterval::Monthly, 3);
    }

    public static function semiAnnually(): self
    {
        return new self(BillingInterval::Monthly, 6);
    }

    public static function weekly(): self
    {
        return new self(BillingInterval::Weekly);
    }

    public static function daily(): self
    {
        return new self(BillingInterval::Daily);
    }

    /**
     * Read a stored key or a common spelling.
     *
     * @throws InvalidArgumentException for a key that names no valid frequency
     */
    public static function fromKey(string $key): self
    {
        return self::tryFromKey($key)
            ?? throw new InvalidArgumentException("Unknown billing interval [{$key}].");
    }

    /** Read a stored key or a common spelling; null when it names no valid frequency. */
    public static function tryFromKey(string $key): ?self
    {
        $key = strtolower(trim($key));
        $key = self::ALIASES[$key] ?? $key;

        if (isset(self::UNITS[$key])) {
            return new self(self::UNITS[$key]);
        }

        if (preg_match('/^([1-9]\d{0,3})-(day|week|month|year)$/', $key, $match) !== 1) {
            return null;
        }

        try {
            return new self(self::UNITS[$match[2]], (int) $match[1]);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** The durable key: `month`, `year`, `week`, `day`, or `{count}-{unit}`. */
    public function key(): string
    {
        $unit = (string) array_search($this->interval, self::UNITS, true);

        return $this->count === 1 ? $unit : "{$this->count}-{$unit}";
    }

    public function isDayBased(): bool
    {
        return $this->interval->isDayBased();
    }

    /**
     * The calendar months one cycle spans.
     *
     * @throws LogicException for a day-based frequency
     */
    public function months(): int
    {
        return $this->count * $this->interval->months();
    }

    /**
     * The days one cycle spans.
     *
     * @throws LogicException for a month-based frequency
     */
    public function days(): int
    {
        return $this->count * $this->interval->days();
    }

    /**
     * The grant cadence that drips on the same boundary as this cycle, or null when no
     * cadence matches (every two weeks, every five months).
     */
    public function cadence(): ?GrantCadence
    {
        if ($this->count === 1) {
            return $this->interval->cadence();
        }

        return match (true) {
            $this->interval === BillingInterval::Monthly && $this->count === 3 => GrantCadence::Quarterly,
            $this->interval === BillingInterval::Monthly && $this->count === 6 => GrantCadence::HalfYearly,
            default => null,
        };
    }

    /**
     * One cycle's share of a month as an exact fraction `[numerator, denominator]`, so an
     * amount per cycle × numerator / denominator is the amount per month (recurring revenue).
     * A month-based cycle is exact (`[1, months]`); a day-based one uses the Gregorian
     * average month.
     *
     * @return array{int, int}
     */
    public function monthlyShare(): array
    {
        return $this->isDayBased()
            ? [self::GREGORIAN_DAYS, self::GREGORIAN_MONTHS * $this->days()]
            : [1, $this->months()];
    }

    /** Order by length: negative when this cycle is shorter than `$other`. */
    public function compare(self $other): int
    {
        return $this->averageDayWeight() <=> $other->averageDayWeight();
    }

    public function equals(self $other): bool
    {
        return $this->interval === $other->interval && $this->count === $other->count;
    }

    /** Length scaled so month- and day-based cycles compare as integers. */
    private function averageDayWeight(): int
    {
        return $this->isDayBased()
            ? $this->days() * self::GREGORIAN_MONTHS
            : $this->months() * self::GREGORIAN_DAYS;
    }
}
