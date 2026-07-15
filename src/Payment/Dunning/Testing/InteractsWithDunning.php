<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\Dunning\Testing;

use Cbox\Billing\Account\Testing\FakeAccountStanding;
use Cbox\Billing\Payment\Dunning\DefaultDelinquencyPolicy;
use Cbox\Billing\Payment\Dunning\DunningRunner;
use Cbox\Billing\Payment\Dunning\ValueObjects\DunningConfig;

/**
 * Wire up the real dunning flow in tests over shared in-memory collaborators, so the
 * package dogfoods its own {@see DefaultDelinquencyPolicy} and {@see DunningRunner}
 * rather than a mock:
 *
 *     $this->dunningAllowList()->allow('vip');
 *     $outcome = $this->dunningRunner()->run('acme', $invoices, $now);
 *     expect($this->dunningStanding()->standingOf('acme')->grantsAccess())->toBeFalse();
 *
 * The runner shares one account-standing store, one state store, and one allow-list with
 * the test, so assertions read the very instances the flow wrote to. The config knobs
 * default to a compact set that is easy to drive; override via {@see DunningConfig()}.
 */
trait InteractsWithDunning
{
    private ?FakeAccountStanding $dunningStandingInstance = null;

    private ?FakeDunningStateStore $dunningStateStoreInstance = null;

    private ?FakeDelinquentAllowList $dunningAllowListInstance = null;

    private ?DunningConfig $dunningConfigInstance = null;

    protected function dunningRunner(): DunningRunner
    {
        return new DunningRunner(
            new DefaultDelinquencyPolicy,
            $this->dunningStanding(),
            $this->dunningStateStore(),
            $this->dunningAllowList(),
            $this->dunningConfig(),
        );
    }

    protected function dunningPolicy(): DefaultDelinquencyPolicy
    {
        return new DefaultDelinquencyPolicy;
    }

    protected function dunningStanding(): FakeAccountStanding
    {
        return $this->dunningStandingInstance ??= new FakeAccountStanding;
    }

    protected function dunningStateStore(): FakeDunningStateStore
    {
        return $this->dunningStateStoreInstance ??= new FakeDunningStateStore;
    }

    protected function dunningAllowList(): FakeDelinquentAllowList
    {
        return $this->dunningAllowListInstance ??= new FakeDelinquentAllowList;
    }

    protected function dunningConfig(): DunningConfig
    {
        return $this->dunningConfigInstance ??= new DunningConfig(
            maxDelinquencyDays: 30,
            minNoticeCount: 3,
            noticeFrequencyDays: 7,
            graceHours: 24,
        );
    }

    protected function useDunningConfig(DunningConfig $config): void
    {
        $this->dunningConfigInstance = $config;
    }
}
