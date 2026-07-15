<?php

declare(strict_types=1);

use Cbox\Billing\Wallet\Support\Pools;
use Cbox\Billing\Wallet\ValueObjects\Pool;

it('ships a default catalog with the intended behaviour matrix', function (): void {
    $included = Pools::included();
    expect($included->key)->toBe(Pools::INCLUDED)
        ->and($included->spendable)->toBeTrue()
        ->and($included->forfeitsOnCancel)->toBeTrue()
        ->and($included->mayGoNegative)->toBeFalse()
        ->and($included->requiresExpiry)->toBeFalse();

    $promo = Pools::promotional();
    expect($promo->spendable)->toBeTrue()
        ->and($promo->forfeitsOnCancel)->toBeFalse()
        ->and($promo->requiresExpiry)->toBeTrue();

    $purchased = Pools::purchased();
    expect($purchased->spendable)->toBeTrue()
        ->and($purchased->mayGoNegative)->toBeTrue()
        ->and($purchased->forfeitsOnCancel)->toBeFalse()
        ->and($purchased->absorbsOverage())->toBeTrue();

    $regulated = Pools::regulated();
    expect($regulated->spendable)->toBeFalse()
        ->and($regulated->reportable)->toBeTrue()
        ->and($regulated->requiresExpiry)->toBeTrue();
});

it('orders the default burn-down promotional, included, then the sink last', function (): void {
    $keys = array_map(fn (Pool $p) => $p->key, Pools::defaultConsumptionOrder());

    expect($keys)->toBe([Pools::PROMOTIONAL, Pools::INCLUDED, Pools::PURCHASED]);
});

it('rejects a pool that may go negative without being spendable', function (): void {
    expect(fn () => new Pool('bad', spendable: false, mayGoNegative: true, forfeitsOnCancel: false, requiresExpiry: false, reportable: false))
        ->toThrow(InvalidArgumentException::class);
});
