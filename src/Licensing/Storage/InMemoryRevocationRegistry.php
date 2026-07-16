<?php

declare(strict_types=1);

namespace Cbox\Billing\Licensing\Storage;

use Cbox\Billing\Licensing\Contracts\RevocationRegistry;
use Cbox\Billing\Licensing\RevocationPublisher;

/**
 * In-memory {@see RevocationRegistry} — the zero-config default and the dogfood
 * registry the package's own tests drive the {@see RevocationPublisher}
 * against. Single process, not durable; a host binds a connection-backed registry so
 * revocations survive a restart and are shared across issuer nodes.
 *
 * Ids are held as set keys, so {@see revoke()} is idempotent and {@see revokedIds()}
 * returns each id once.
 */
class InMemoryRevocationRegistry implements RevocationRegistry
{
    /** @var array<string, true> revoked license id → present */
    private array $revoked = [];

    public function revoke(string $licenseId): void
    {
        $this->revoked[$licenseId] = true;
    }

    public function revokedIds(): array
    {
        return array_keys($this->revoked);
    }
}
