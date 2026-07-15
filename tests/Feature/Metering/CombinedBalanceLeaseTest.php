<?php

declare(strict_types=1);

use Cbox\Billing\Metering\Buffers\ArrayUsageBuffer;
use Cbox\Billing\Metering\Enums\OverageBehaviour;
use Cbox\Billing\Metering\Exceptions\QuotaExceeded;
use Cbox\Billing\Metering\LeasedEnforcement;
use Cbox\Billing\Metering\Sources\WalletAllowanceLeaseSource;
use Cbox\Billing\Metering\Sources\WalletIncludedAllowanceResolver;
use Cbox\Billing\Metering\Stores\CacheLocalStore;
use Cbox\Billing\Metering\Testing\FakeMeterPolicyResolver;
use Cbox\Billing\Metering\ValueObjects\MeterPolicy;
use Cbox\Billing\Wallet\InMemoryWallet;
use Cbox\Billing\Wallet\Support\Pools;
use Cbox\Billing\Wallet\ValueObjects\CreditGrant;
use Cbox\Billing\Wallet\ValueObjects\Denomination;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

function walletFor(callable $setup): InMemoryWallet
{
    $wallet = new InMemoryWallet;
    $setup($wallet);

    return $wallet;
}

function enforcementOver(WalletAllowanceLeaseSource $source, int $refillSize = 1_000): LeasedEnforcement
{
    return new LeasedEnforcement(
        store: new CacheLocalStore(new Repository(new ArrayStore)),
        source: $source,
        buffer: new ArrayUsageBuffer,
        service: 'test',
        refillSize: $refillSize,
        ids: fn (): string => 'id',
        clock: static fn (): int => 1_700_000_000_000,
    );
}

it('derives the meter budget from the combined spendable balance (included + credits)', function (): void {
    $tokens = Denomination::unit('ai.tokens');
    $wallet = walletFor(function (InMemoryWallet $w) use ($tokens): void {
        $w->grant(new CreditGrant('inc', 'org_a', Pools::included(), $tokens, 100, expiresAt: null, grantedAt: 1));
        $w->grant(new CreditGrant('promo', 'org_a', Pools::promotional(), $tokens, 50, expiresAt: 9_999_999_999_999, grantedAt: 1));
    });

    $source = new WalletAllowanceLeaseSource($wallet, clock: static fn (): int => 1_000);

    // Included 100 + promotional 50 = one combined budget of 150.
    expect($source->combinedBalance('org_a', 'ai.tokens'))->toBe(150);
});

it('a PAYG sink debt never shrinks the leasable budget (each pool floored at zero)', function (): void {
    $tokens = Denomination::unit('ai.tokens');
    $wallet = walletFor(function (InMemoryWallet $w) use ($tokens): void {
        $w->grant(new CreditGrant('inc', 'org_a', Pools::included(), $tokens, 100, expiresAt: null, grantedAt: 1));
        $w->grant(new CreditGrant('debt', 'org_a', Pools::purchased(), $tokens, -30, expiresAt: null, grantedAt: 1));
    });

    $source = new WalletAllowanceLeaseSource($wallet, clock: static fn (): int => 1_000);

    expect($source->combinedBalance('org_a', 'ai.tokens'))->toBe(100);
});

it('enforces one hard limit over the combined balance and blocks beyond it', function (): void {
    $tokens = Denomination::unit('ai.tokens');
    $wallet = walletFor(function (InMemoryWallet $w) use ($tokens): void {
        $w->grant(new CreditGrant('inc', 'org_a', Pools::included(), $tokens, 100, expiresAt: null, grantedAt: 1));
        $w->grant(new CreditGrant('promo', 'org_a', Pools::promotional(), $tokens, 50, expiresAt: 9_999_999_999_999, grantedAt: 1));
    });

    $source = new WalletAllowanceLeaseSource($wallet, clock: static fn (): int => 1_000);
    $enforcement = enforcementOver($source, refillSize: 40);

    // Spend the whole combined 150 across several reservations…
    $enforcement->commit($enforcement->reserve('org_a', 'ai.tokens', 90), 90);
    $enforcement->commit($enforcement->reserve('org_a', 'ai.tokens', 60), 60);

    // …then the 151st unit is refused: the combined balance is the hard limit.
    expect(fn () => $enforcement->reserve('org_a', 'ai.tokens', 1))->toThrow(QuotaExceeded::class)
        ->and($source->leasedOut('org_a', 'ai.tokens'))->toBeLessThanOrEqual(150);
});

it('sources a MeterPolicy allowance from the included-pool grant (deny-by-default preserved)', function (): void {
    $tokens = Denomination::unit('ai.tokens');
    $wallet = walletFor(function (InMemoryWallet $w) use ($tokens): void {
        $w->grant(new CreditGrant('inc', 'org_a', Pools::included(), $tokens, 100, expiresAt: null, grantedAt: 1));
    });

    // The base resolver authors weight + overage, but allowance 0 — the number comes
    // from the included grant, not the hand-authored policy.
    $base = (new FakeMeterPolicyResolver)->set('org_a', 'ai.tokens', MeterPolicy::metered(0, 2.0, OverageBehaviour::Bill));
    $resolver = new WalletIncludedAllowanceResolver($base, $wallet, clock: static fn (): int => 1_000);

    $policy = $resolver->resolve('org_a', 'ai.tokens');

    expect($policy)->not->toBeNull()
        ->and($policy->allowance)->toBe(100)               // sourced from the included-pool grant
        ->and($policy->multiplier)->toBe(2.0)              // base weight preserved
        ->and($policy->overage)->toBe(OverageBehaviour::Bill)
        ->and($resolver->resolve('org_a', 'unmetered'))->toBeNull(); // deny-by-default
});

it('isolates included allowances per meter — one meter never funds another', function (): void {
    $wallet = walletFor(function (InMemoryWallet $w): void {
        $w->grant(new CreditGrant('inc', 'org_a', Pools::included(), Denomination::unit('ai.tokens'), 100, expiresAt: null, grantedAt: 1));
    });

    $base = (new FakeMeterPolicyResolver)
        ->set('org_a', 'ai.tokens', MeterPolicy::metered(0, 1.0, OverageBehaviour::Bill))
        ->set('org_a', 'cpu.ms', MeterPolicy::metered(0, 1.0, OverageBehaviour::Bill));
    $resolver = new WalletIncludedAllowanceResolver($base, $wallet, clock: static fn (): int => 1_000);

    // ai.tokens has an included grant; cpu.ms does not — its exemption stays 0.
    expect($resolver->resolve('org_a', 'ai.tokens')->allowance)->toBe(100)
        ->and($resolver->resolve('org_a', 'cpu.ms')->allowance)->toBe(0);
});
