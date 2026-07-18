<?php

declare(strict_types=1);

namespace Cbox\Billing\Retention\ValueObjects;

use Cbox\Billing\Pricing\ValueObjects\Coupon;
use Cbox\Billing\Retention\Enums\SaveOfferType;
use InvalidArgumentException;

/**
 * A save-offer presented to a cancelling subscriber. A single immutable value carrying the
 * {@see SaveOfferType} and only the typed params that type needs (the others stay null) —
 * the same one-VO-many-shapes shape the {@see Coupon}
 * uses. Construct through the named constructors so an offer can never carry the wrong
 * params for its type; the constructor validates the invariants deny-by-default.
 *
 *  - {@see freeMonth()} — `$freeMonths` free months (≥ 1).
 *  - {@see discount()}  — `$discountPercent` off (0–100) for `$durationCycles` cycles (≥ 1).
 *  - {@see pause()}     — pause for `$pauseCycles` cycles (≥ 1).
 *  - {@see downgrade()} — move onto `$targetProductId` at `$targetPriceId`.
 *  - {@see custom()}    — host-handled; carries only its key/label.
 */
readonly class SaveOffer
{
    public function __construct(
        public SaveOfferType $type,
        public string $key,
        public string $label,
        public ?int $freeMonths = null,
        public ?int $discountPercent = null,
        public ?int $durationCycles = null,
        public ?int $pauseCycles = null,
        public ?string $targetProductId = null,
        public ?string $targetPriceId = null,
    ) {
        if ($key === '') {
            throw new InvalidArgumentException('A save-offer needs a key.');
        }

        match ($type) {
            SaveOfferType::FreeMonth => $this->assertFreeMonth(),
            SaveOfferType::Discount => $this->assertDiscount(),
            SaveOfferType::Pause => $this->assertPause(),
            SaveOfferType::Downgrade => $this->assertDowngrade(),
            SaveOfferType::Custom => null,
        };
    }

    public static function freeMonth(string $key, string $label, int $freeMonths = 1): self
    {
        return self::build(SaveOfferType::FreeMonth, $key, $label, freeMonths: $freeMonths);
    }

    public static function discount(string $key, string $label, int $discountPercent, int $durationCycles): self
    {
        return self::build(SaveOfferType::Discount, $key, $label, discountPercent: $discountPercent, durationCycles: $durationCycles);
    }

    public static function pause(string $key, string $label, int $pauseCycles): self
    {
        return self::build(SaveOfferType::Pause, $key, $label, pauseCycles: $pauseCycles);
    }

    public static function downgrade(string $key, string $label, string $targetProductId, string $targetPriceId): self
    {
        return self::build(SaveOfferType::Downgrade, $key, $label, targetProductId: $targetProductId, targetPriceId: $targetPriceId);
    }

    public static function custom(string $key, string $label): self
    {
        return self::build(SaveOfferType::Custom, $key, $label);
    }

    private static function build(
        SaveOfferType $type,
        string $key,
        string $label,
        ?int $freeMonths = null,
        ?int $discountPercent = null,
        ?int $durationCycles = null,
        ?int $pauseCycles = null,
        ?string $targetProductId = null,
        ?string $targetPriceId = null,
    ): self {
        return new self($type, $key, $label, $freeMonths, $discountPercent, $durationCycles, $pauseCycles, $targetProductId, $targetPriceId);
    }

    private function assertFreeMonth(): void
    {
        if ($this->freeMonths === null || $this->freeMonths < 1) {
            throw new InvalidArgumentException('A free-month offer needs at least one free month.');
        }
    }

    private function assertDiscount(): void
    {
        if ($this->discountPercent === null || $this->discountPercent < 0 || $this->discountPercent > 100) {
            throw new InvalidArgumentException('A discount offer needs a percentage between 0 and 100.');
        }

        if ($this->durationCycles === null || $this->durationCycles < 1) {
            throw new InvalidArgumentException('A discount offer needs a duration of at least one cycle.');
        }
    }

    private function assertPause(): void
    {
        if ($this->pauseCycles === null || $this->pauseCycles < 1) {
            throw new InvalidArgumentException('A pause offer needs a duration of at least one cycle.');
        }
    }

    private function assertDowngrade(): void
    {
        if ($this->targetProductId === null || $this->targetProductId === '' || $this->targetPriceId === null || $this->targetPriceId === '') {
            throw new InvalidArgumentException('A downgrade offer needs a target product and price.');
        }
    }
}
