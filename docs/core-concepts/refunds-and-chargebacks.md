---
title: Refunds & chargebacks
description: First-class refunds and chargebacks modelled as ledger reversals, with a register and a handler that can flag the account disputed.
weight: 31
---

# Refunds & chargebacks

Refunds and chargebacks both **reverse** money, so both are ledger reversals — not
mutations of a posted entry (the [ledger](ledger.md) is append-only).

## Refunds

A `RefundRequest` carries the amount, a `RefundReason`, and a `ReversalKind`;
`Refunder` produces a `Refund`:

```php
interface Refunder
{
    public function refund(RefundRequest $request): Refund;
}
```

`DefaultRefunder` posts the reversal to the ledger via `ReversalPosting` and records
it to a `RefundRepository`. It raises `CannotRefund` when the request exceeds the
refundable amount, so you cannot refund more than was charged. Refunds against the
gateway go through the `PaymentGateway::refund` seam.

## Chargebacks

A chargeback is a bank-initiated reversal. A `ChargebackNotice` drives
`ChargebackHandler`:

```php
interface ChargebackHandler
{
    public function handle(ChargebackNotice $notice): Chargeback;
}
```

`DefaultChargebackHandler` records the `Chargeback` to a `ChargebackRegister` and
posts the reversal, and can flag the [account](accounts.md) `Disputed` so standing
reflects the open dispute. Because the register is separate from refunds, a
chargeback that follows a refund (or vice versa) is reconcilable rather than
double-counted.

## Testing

`InteractsWithRefunds`, `FakeRefunder`, and `FakeChargebackHandler` drive the
reversal postings and the over-refund guard.

## Related

- [Ledger](ledger.md)
- [Payments & dunning](payments-and-dunning.md)
- [Accounts](accounts.md)
