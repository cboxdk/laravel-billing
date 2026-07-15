---
title: Issue an invoice
description: Build a tax-composed quote, confirm it, and fix it to a per-seller legal invoice number.
weight: 46
---

# Issue an invoice

An invoice is a **confirmed** [quote](../core-concepts/quotes-and-invoicing.md)
fixed to a legal number. The flow is: build the quote, confirm it, issue.

## Build a quote

`QuoteBuilder` composes tax (via `cboxdk/laravel-tax`) and any wallet credit into
the totals. The `QuoteContext` carries the place of supply, customer type, and the
seller's tax registrations:

```php
use Cbox\Billing\Money\Money;
use Cbox\Billing\Quote\Contracts\QuoteBuilder;
use Cbox\Billing\Quote\ValueObjects\LineInput;

$quote = $quotes->build(
    lines: [
        new LineInput(description: 'Pro plan — July', quantity: 1, unitAmount: Money::ofMinor(3_000, 'EUR')),
    ],
    context: $quoteContext,
);
```

If the jurisdiction cannot be resolved, the quote comes back **tax-pending** — a
correct "we don't know the tax yet" state, never a wrong number.

## Issue the invoice

```php
use Cbox\Billing\Invoice\Contracts\Invoicer;

$invoice = $invoicer->issue(
    quote:   $quote,      // must be confirmed, not tax-pending
    seller:  $sellerEntity,
    account: $accountId,
    at:      new DateTimeImmutable(),
);
```

`issue`:

- raises `CannotInvoicePendingQuote` if the quote is still tax-pending;
- draws the number from the **per-seller** `InvoiceNumberSequence` — monotonic and
  gapless within each seller of record;
- stamps and guards the [account's billing currency](../core-concepts/accounts.md),
  so the first finalized invoice locks it.

## Credit notes

To reverse invoiced value, issue a `CreditNote`, which draws from its own per-seller
gapless `CreditNoteNumberSequence`.

## Related

- [Quotes & invoicing](../core-concepts/quotes-and-invoicing.md)
- [Catalog & pricing](../core-concepts/catalog-and-pricing.md)
- [Accounts](../core-concepts/accounts.md)
