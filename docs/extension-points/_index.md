---
title: Extension points
description: The contracts you bind, the storage adapters you select, the gateway adapters you plug in, and the dogfooded testing seams.
weight: 60
---

# Extension points

Every module is contracts-first: a contract in `*/Contracts`, an in-memory default
bound in the module's service provider, and a durable or fake alternative you swap
in. Nothing in the library is final — you replace a binding, you don't fork.

## In this section

| Page | What |
| --- | --- |
| [Contracts & bindings](contracts-and-bindings.md) | The one contract per module and how to rebind it. |
| [Storage adapters](storage-adapters.md) | `memory` vs `database` per module, and the ClickHouse seam. |
| [Payment gateways](payment-gateways.md) | Implement `PaymentGateway` and the webhook verifier. |
| [Testing](testing.md) | The `InteractsWith*` traits and `Fake*` doubles. |
