<?php

declare(strict_types=1);

namespace Cbox\Billing\Licensing;

use Cbox\Billing\Licensing\Contracts\IssuedLicenseStore;
use Cbox\Billing\Licensing\Contracts\LicenseProfileResolver;
use Cbox\Billing\Licensing\Contracts\RevocationRegistry;
use Cbox\Billing\Licensing\Storage\InMemoryIssuedLicenseStore;
use Cbox\Billing\Licensing\Storage\InMemoryRevocationRegistry;
use Cbox\Billing\Licensing\ValueObjects\LicenseProfile;
use Cbox\License\Contracts\LicenseIssuer;
use Cbox\License\Contracts\RevocationListIssuer;
use Cbox\License\ValueObjects\LicenseLimits;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the on-prem license issuer. Binds the profile resolver to the configured map
 * (deny-by-default: empty, so nothing is licensable until a host declares a profile),
 * and the issued-license store + revocation registry to their in-memory defaults,
 * which a host rebinds with durable implementations of the same contracts.
 *
 * **This provider deliberately does NOT bind {@see LicenseIssuer} or
 * {@see RevocationListIssuer}.** Those hold the issuer PRIVATE key, which is a host
 * concern: the host constructs `Ed25519LicenseIssuer` / `Ed25519RevocationListIssuer`
 * from its own secret config and binds them in the container. This module is
 * key-agnostic — {@see LicenseMint} and {@see RevocationPublisher} depend only on the
 * contracts. They are registered here as lazy resolutions, so resolving either one
 * before the host has bound the core issuer surfaces a clear container error rather
 * than minting with no key.
 */
class LicensingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LicenseProfileResolver::class, static function (Application $app): ConfiguredLicenseProfileResolver {
            $config = $app->make(Config::class)->get('billing.licensing.profiles', []);

            return new ConfiguredLicenseProfileResolver(self::buildProfiles(is_array($config) ? $config : []));
        });

        $this->app->singleton(IssuedLicenseStore::class, static fn (): InMemoryIssuedLicenseStore => new InMemoryIssuedLicenseStore);

        $this->app->singleton(RevocationRegistry::class, static fn (): InMemoryRevocationRegistry => new InMemoryRevocationRegistry);

        // Key-agnostic services: they resolve the host-bound core issuers lazily.
        $this->app->bind(LicenseMint::class, static fn (Application $app): LicenseMint => new LicenseMint(
            $app->make(LicenseIssuer::class),
        ));

        $this->app->bind(RevocationPublisher::class, static fn (Application $app): RevocationPublisher => new RevocationPublisher(
            $app->make(RevocationListIssuer::class),
            $app->make(RevocationRegistry::class),
        ));

        $this->app->bind(SubscriptionLicensePolicy::class, static function (Application $app): SubscriptionLicensePolicy {
            $grace = $app->make(Config::class)->get('billing.licensing.grace_seconds', 0);

            return new SubscriptionLicensePolicy(is_numeric($grace) ? (int) $grace : 0);
        });
    }

    /**
     * Build the plan-id → {@see LicenseProfile} map from config. Deny-by-default: a
     * malformed or non-array entry is skipped rather than trusted, so only
     * well-formed, explicitly-declared plans become licensable.
     *
     * @param  array<array-key, mixed>  $config
     * @return array<string, LicenseProfile>
     */
    private static function buildProfiles(array $config): array
    {
        $profiles = [];

        foreach ($config as $planId => $definition) {
            if (! is_string($planId) || ! is_array($definition)) {
                continue;
            }

            $profiles[$planId] = new LicenseProfile(
                plan: $planId,
                entitlements: self::stringList($definition['entitlements'] ?? []),
                limits: LicenseLimits::fromClaim(self::limitsClaim($definition['limits'] ?? [])),
            );
        }

        return $profiles;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $list = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $list[] = $item;
            }
        }

        return $list;
    }

    /**
     * @return array{organizations?: int|null, seats?: int|null, environments?: int|null}
     */
    private static function limitsClaim(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $claim = [];
        foreach (['organizations', 'seats', 'environments'] as $dimension) {
            if (array_key_exists($dimension, $value)) {
                $dim = $value[$dimension];
                $claim[$dimension] = is_int($dim) ? $dim : null;
            }
        }

        return $claim;
    }
}
