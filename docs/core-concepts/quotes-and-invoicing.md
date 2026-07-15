---
title: Quotes & invoicing
description: Tax-composed quotes with progressive resolution, and invoices that fix a confirmed quote to a per-seller legal number, with credit notes.
weight: 29
---

# Quotes & invoicing

## Quotes

A `Quote` is the confirmable consequence-preview: net, tax, gross, credit applied,
and amount due now. `QuoteBuilder` composes `cboxdk/laravel-tax` (seller-of-record
routing + per-line tax) and wallet credit into it:

```php
interface QuoteBuilder
{
    public function build(array $lines, QuoteContext $context): Quote;
}
```

Tax resolution is **progressive**: an unresolved jurisdiction returns a
**tax-pending** quote (`QuoteStatus`, `TaxResolution`) — never a wrong number. Line
amounts are rounded per line, consistent with the settlement gateway, so a quote
shown at confirm matches the charge (the [preview-equals-charge](subscriptions.md)
discipline).

## Invoicing

`Invoicer` fixes a **confirmed** quote to a legal invoice:

```php
interface Invoicer
{
    public function issue(Quote $quote, SellerEntity $seller, string $account, DateTimeImmutable $at): Invoice;
}
```

`DefaultInvoicer`:

- **Refuses a tax-pending quote** (`CannotInvoicePendingQuote`) — you cannot issue a
  legal document with an unresolved number.
- Draws the invoice number from a **per-seller** `InvoiceNumberSequence`
  (`next(SellerEntity)`), which is **monotonic and gapless per seller** — each
  seller of record keeps its own legal series.
- Stamps and guards the account's [billing currency](accounts.md) so the first
  finalized invoice locks the currency.

### Credit notes

A `CreditNote` reverses invoiced value and draws from its own per-seller
`CreditNoteNumberSequence`, kept as a separate gapless series.

## Related

- [Catalog & pricing](catalog-and-pricing.md)
- [Accounts](accounts.md)
- [Refunds & chargebacks](refunds-and-chargebacks.md)
- [Cookbook: issue an invoice](../cookbook/issue-an-invoice.md)
