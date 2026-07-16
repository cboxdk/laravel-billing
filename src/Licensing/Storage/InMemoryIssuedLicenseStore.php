<?php

declare(strict_types=1);

namespace Cbox\Billing\Licensing\Storage;

use Cbox\Billing\Licensing\Contracts\IssuedLicenseStore;
use Cbox\Billing\Licensing\LicenseMint;
use Cbox\Billing\Licensing\ValueObjects\IssuedLicense;

/**
 * In-memory {@see IssuedLicenseStore} — the zero-config default and the dogfood store
 * the package's own tests exercise the real {@see LicenseMint}
 * against. Single process, not durable; a host binds a connection-backed store for
 * production.
 *
 * A deployment holds at most one live license, so {@see save()} overwrites the
 * deployment index — a renewal (fresh id, extended window) supersedes the prior
 * license under the same deployment id, while both remain findable by their own id.
 */
class InMemoryIssuedLicenseStore implements IssuedLicenseStore
{
    /** @var array<string, IssuedLicense> keyed by license id */
    private array $byId = [];

    /** @var array<string, string> deployment id → current license id */
    private array $currentByDeployment = [];

    public function save(IssuedLicense $license): void
    {
        $this->byId[$license->id] = $license;
        $this->currentByDeployment[$license->deploymentId] = $license->id;
    }

    public function find(string $id): ?IssuedLicense
    {
        return $this->byId[$id] ?? null;
    }

    public function forCustomer(string $customerId): array
    {
        $matches = [];

        foreach ($this->byId as $license) {
            if ($license->customerId === $customerId) {
                $matches[] = $license;
            }
        }

        return $matches;
    }

    public function forDeployment(string $deploymentId): ?IssuedLicense
    {
        $id = $this->currentByDeployment[$deploymentId] ?? null;

        return $id === null ? null : ($this->byId[$id] ?? null);
    }
}
