<?php

declare(strict_types=1);

namespace Cbox\Billing\Metering\Sources;

use Cbox\Billing\Metering\Contracts\MeterPolicyResolver;
use Cbox\Billing\Metering\ValueObjects\MeterPolicy;
use Cbox\Billing\Wallet\Contracts\Wallet;
use Cbox\Billing\Wallet\Support\Pools;
use Cbox\Billing\Wallet\ValueObjects\Denomination;
use Cbox\Billing\Wallet\ValueObjects\Pool;
use Closure;

/**
 * The ADR-0013 refinement of the allowance's home: a decorator that sources each
 * meter's included allowance (the exempt/free size) from its recurring grant into the
 * `included` pool, rather than a hand-authored scalar. It wraps a base resolver that
 * still decides entitlement, weight, and overage per meter (ADR-0005), and overrides
 * only the allowance with the Wallet's current `included`-pool balance for that meter.
 *
 * Deny-by-default is preserved: an ungranted `(org, meter)` resolves to `null` on the
 * base resolver and is refused. Isolation holds too — the balance is read per meter
 * denomination, so one meter's included grant never funds another's exemption.
 */
class WalletIncludedAllowanceResolver implements MeterPolicyResolver
{
    private Pool $includedPool;

    /** @var Closure(): int */
    private Closure $clock;

    /**
     * @param  (Closure(): int)|null  $clock  millisecond-epoch clock (deterministic in tests)
     */
    public function __construct(
        private readonly MeterPolicyResolver $base,
        private readonly Wallet $wallet,
        ?Closure $clock = null,
        ?Pool $includedPool = null,
    ) {
        $this->includedPool = $includedPool ?? Pools::included();
        $this->clock = $clock ?? static fn (): int => (int) round(microtime(true) * 1000);
    }

    public function resolve(string $org, string $meter): ?MeterPolicy
    {
        $policy = $this->base->resolve($org, $meter);
        if ($policy === null) {
            return null; // deny-by-default: no base entitlement, no metering
        }

        $included = max(0, $this->wallet->balance(
            $org,
            $this->includedPool,
            Denomination::unit($meter),
            ($this->clock)(),
        ));

        return $policy->withAllowance($included);
    }
}
