<?php

declare(strict_types=1);

namespace Cbox\Billing\Refund\Testing;

use Cbox\Billing\Account\Testing\FakeAccountStanding;
use Cbox\Billing\Invoice\Sequences\InMemoryCreditNoteNumberSequence;
use Cbox\Billing\Ledger\InMemoryLedger;
use Cbox\Billing\Payment\Testing\FakePaymentGateway;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;
use Cbox\Billing\Refund\DefaultChargebackHandler;
use Cbox\Billing\Refund\DefaultRefunder;
use Cbox\Billing\Refund\Storage\InMemoryChargebackRegister;
use Cbox\Billing\Refund\Storage\InMemoryRefundRepository;
use Cbox\Billing\Wallet\InMemoryWallet;

/**
 * Wire up the real refund + chargeback flows in tests over shared in-memory
 * collaborators, so the package dogfoods its own {@see DefaultRefunder} and
 * {@see DefaultChargebackHandler} rather than a mock:
 *
 *     $refund = $this->refunder()->refund(RefundRequest::full(...));
 *     expect($this->refundLedger()->balance('receivable:acme', 'EUR')->minor())->toBe(-12500);
 *
 * The refunder and chargeback handler share one ledger and (for the chargeback) one
 * account-standing store, so a test asserts against the same instances the flow wrote
 * to. The gateway defaults to a {@see FakePaymentGateway} that settles refunds.
 */
trait InteractsWithRefunds
{
    private ?InMemoryLedger $refundLedgerInstance = null;

    private ?InMemoryWallet $refundWalletInstance = null;

    private ?InMemoryRefundRepository $refundRepositoryInstance = null;

    private ?InMemoryChargebackRegister $chargebackRegisterInstance = null;

    private ?FakeAccountStanding $refundStandingInstance = null;

    private ?FakePaymentGateway $refundGatewayInstance = null;

    protected function refunder(): DefaultRefunder
    {
        return new DefaultRefunder(
            new InMemoryCreditNoteNumberSequence,
            $this->refundRepository(),
            $this->refundLedger(),
            $this->refundGateway(),
            $this->refundWallet(),
        );
    }

    protected function chargebackHandler(): DefaultChargebackHandler
    {
        return new DefaultChargebackHandler(
            $this->chargebackRegister(),
            $this->refundLedger(),
            $this->refundStanding(),
        );
    }

    protected function refundLedger(): InMemoryLedger
    {
        return $this->refundLedgerInstance ??= new InMemoryLedger;
    }

    protected function refundWallet(): InMemoryWallet
    {
        return $this->refundWalletInstance ??= new InMemoryWallet;
    }

    protected function refundRepository(): InMemoryRefundRepository
    {
        return $this->refundRepositoryInstance ??= new InMemoryRefundRepository;
    }

    protected function chargebackRegister(): InMemoryChargebackRegister
    {
        return $this->chargebackRegisterInstance ??= new InMemoryChargebackRegister;
    }

    protected function refundStanding(): FakeAccountStanding
    {
        return $this->refundStandingInstance ??= new FakeAccountStanding;
    }

    protected function refundGateway(): FakePaymentGateway
    {
        return $this->refundGatewayInstance ??= new FakePaymentGateway(
            PaymentResult::succeeded('ch_fake'),
        );
    }
}
