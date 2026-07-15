<?php

declare(strict_types=1);

namespace Cbox\Billing\Entitlement\Audit\Sources;

use Cbox\Billing\Entitlement\Audit\Contracts\ExpectedEntitlements;
use Cbox\Billing\Entitlement\Audit\ValueObjects\AuditTarget;

/**
 * An in-memory {@see ExpectedEntitlements} — for tests and small hosts, and the shipped
 * default (constructed empty, so the audit has nothing to check until the host supplies
 * targets: deny-by-default extends to "audit nothing until told what to expect").
 *
 * The targets are the INDEPENDENT oracle: build them from plan/catalog definition, never
 * by reading back the entitlement rows the audit will inspect. `expect()` appends one
 * org/plan and its expected keys.
 */
readonly class InMemoryExpectedEntitlements implements ExpectedEntitlements
{
    /** @var list<AuditTarget> */
    private array $targets;

    /**
     * @param  list<AuditTarget>  $targets
     */
    public function __construct(array $targets = [])
    {
        $this->targets = $targets;
    }

    /**
     * Return a copy with one more org/plan expectation. Immutable: the receiver is
     * unchanged.
     *
     * @param  list<string>  $expectedKeys
     */
    public function expect(string $org, string $plan, array $expectedKeys): self
    {
        return new self([...$this->targets, new AuditTarget($org, $plan, $expectedKeys)]);
    }

    public function targets(): iterable
    {
        return $this->targets;
    }
}
