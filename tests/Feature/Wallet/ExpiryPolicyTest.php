<?php

declare(strict_types=1);

use Cbox\Billing\Wallet\ValueObjects\Duration;
use Cbox\Billing\Wallet\ValueObjects\EndOfPeriod;
use Cbox\Billing\Wallet\ValueObjects\NeverExpires;

it('EndOfPeriod expires a lot at the cadence period end (reset, no rollover)', function (): void {
    expect((new EndOfPeriod)->expiresAt(grantedAtMs: 1_000, periodEndMs: 5_000))->toBe(5_000);
});

it('Duration expires a lot a fixed span after it was granted (rollover)', function (): void {
    // 1 hour = 3_600_000 ms after grant, independent of the period end.
    expect((new Duration(3_600))->expiresAt(grantedAtMs: 1_000, periodEndMs: 5_000))->toBe(1_000 + 3_600_000);
});

it('Never yields a null expiry', function (): void {
    expect((new NeverExpires)->expiresAt(grantedAtMs: 1_000, periodEndMs: 5_000))->toBeNull();
});

it('rejects a non-positive Duration', function (): void {
    expect(fn () => new Duration(0))->toThrow(InvalidArgumentException::class);
});
