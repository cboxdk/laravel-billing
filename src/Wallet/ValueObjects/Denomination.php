<?php

declare(strict_types=1);

namespace Cbox\Billing\Wallet\ValueObjects;

/**
 * What a credit (or a demand) is measured in — either MONEY (an ISO currency, so
 * amounts are minor units) or UNIT (a specific meter, so amounts are that meter's
 * units, e.g. `api.calls`). A grant can only cover a demand with the same
 * denomination; monetary credits cover a usage demand only after it is priced.
 */
readonly class Denomination
{
    private function __construct(
        public bool $isMoney,
        public string $code,
    ) {}

    public static function money(string $currency): self
    {
        return new self(true, $currency);
    }

    public static function unit(string $meter): self
    {
        return new self(false, $meter);
    }

    public function matches(self $other): bool
    {
        return $this->isMoney === $other->isMoney && $this->code === $other->code;
    }
}
