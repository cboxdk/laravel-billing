---
title: Configuration
description: The config/billing.php keys — account, payment/dunning, metering, entitlement, and reconciliation.
weight: 70
---

# Configuration

All configuration lives in `config/billing.php`. Publish it with:

```bash
php artisan vendor:publish --tag="billing-config"
```

Everything has a working default (in-memory stores, sensible dunning and lease
knobs), so you only publish when you want durable storage or want to tune
behaviour.

## In this section

- [Configuration reference](reference.md) — every key, its environment variable,
  and what it controls.

## Sections at a glance

| Section | Controls |
| --- | --- |
| `account` | Where the billing-currency lock lives. |
| `payment.dunning` | How a delinquent account is chased and suspended. |
| `metering` | Lease sizing, enforcement fail-open/closed, dedup window, event-log store. |
| `entitlement.rollout` | Chunk size for bulk entitlement rollout. |
| `reconciliation` | Ingest-lag clamp, aged-out window, delta currency, checkpoint store. |
