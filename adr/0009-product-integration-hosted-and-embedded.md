# ADR-0009 — Product integration: hosted sessions and embedded intents

**Status:** Accepted (2026-07-16)

## Context

Products that bill on Cbox Billing span a wide range: some want "bill my users with the
least code possible", others want a fully custom billing experience inside their own UI.
Payment capture constrains the design — card data entry and Strong Customer
Authentication (SCA / 3-D Secure) must happen **client-side through the payment gateway's
own element** (never through our servers, for PCI scope and because the gateway owns the
authentication challenge). Card details never reach Cbox Billing; we only ever hold a
gateway `client_secret` / token reference.

## Decision

Offer **two integration paths over one gateway-agnostic seam**:

- **Hosted sessions (fast path).** The product creates a **Checkout** or **Portal**
  session (`createCheckoutSession(org, plan, return_url)`, `createPortalSession(org,
  return_url)`) and redirects the user to a Cbox-Billing-hosted page that renders the
  configured gateway's payment element, handles SCA, and returns the user on completion.
  Minimal product-side code.
- **Embedded intents (full control).** The product builds its own UI with the management
  API/SDK and mounts the gateway's element itself. Cbox Billing creates a
  `PaymentIntent` / `SetupIntent` via the gateway adapter and returns `{gateway,
  publishable_key, client_secret}`; the product's frontend confirms client-side.

### Payment model (both paths)

- **The webhook is the source of truth.** A subscription is activated / an invoice marked
  paid **only** on the gateway's `succeeded` webhook (via `WebhookIngest`, exactly-once) —
  never on the client-side confirmation alone.
- **`requires_action` (SCA) is a first-class payment state.** Do not activate until
  authenticated; surface the `client_secret` so the element can complete the challenge.
  (The webhook event model must represent `requires_action` / `processing` explicitly —
  a two- or three-state collapse is insufficient for element integration.)
- **`SetupIntent`** saves a payment method for **off-session** renewals (no immediate
  charge); **`PaymentIntent`** charges an invoice on-session.
- **Off-session renewal that returns `authentication_required`** fails the payment →
  dunning (ADR/dunning) + a **re-authenticate hook** so the product can prompt the
  customer on-session.
- **Idempotency keys** on intent creation and confirmation; **declines** surfaced with a
  clear reason; payment-method **attachment** + a default method per billing account.

## Consequences

- Gateway adapters expose `PaymentIntent` / `SetupIntent` / payment-method operations and
  the **publishable key**, behind the existing `PaymentGateway` seam.
- The deployable app hosts **Checkout** + **Customer Portal** pages and a **session API**.
- The client SDK offers **both** session helpers (`createCheckoutSession`,
  `createPortalSession`) and intent helpers (`createSetupIntent`, `createPaymentIntent`,
  payment-method management) returning `{gateway, publishable_key, client_secret}`.
- `WebhookEventType` gains explicit `requires_action` / `processing` members; activation is
  gated on `succeeded`.

## Alternatives considered

- **One path only.** Rejected — hosted-only caps the ceiling for products that want their
  own UX; embedded-only raises the integration floor for products that just want to bill.
- **Route card data through our servers.** Rejected — PCI scope and gateway-owned SCA make
  client-side capture with a `client_secret` mandatory.
