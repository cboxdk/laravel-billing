<?php

declare(strict_types=1);

namespace Cbox\Billing\Account\Testing;

/**
 * Wire up an account-standing store in tests with the dogfood fake:
 *
 *     $standing = $this->accountStanding();
 *     $standing->flag('org_a', AccountStandingState::Disputed, 'dp_1');
 *     expect($standing->standingOf('org_a')->grantsAccess())->toBeFalse();
 */
trait InteractsWithAccountStanding
{
    private ?FakeAccountStanding $accountStandingFake = null;

    protected function accountStanding(): FakeAccountStanding
    {
        return $this->accountStandingFake ??= new FakeAccountStanding;
    }
}
