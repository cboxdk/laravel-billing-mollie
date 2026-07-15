---
title: Idempotent webhooks
weight: 2
description: How replayed and retried Mollie webhooks are verified once and applied once on the shared billing seam.
---

# Idempotent webhooks

Mollie retries webhook deliveries and re-notifies on every state change, so the same
effect must never be applied twice (no double-settle). This adapter no longer carries
its own webhook contracts: it plugs into the **canonical webhook seam** shipped by
`cboxdk/laravel-billing`, contributing only the Mollie-specific signature check, a
payment fetch, and a pair of durable stores. The engine owns the exactly-once apply.

## 1. Signature verification and payment fetch (deny-by-default)

`MollieApiWebhookVerifier` implements the engine's shared
`Cbox\Billing\Payment\Contracts\WebhookVerifier`. It takes a `WebhookPayload` (the raw
body plus headers), reads the `X-Mollie-Signature` header, and wraps the SDK's
`SignatureValidator` — HMAC-SHA256 over the raw body against the webhook signing
secret. We never hand-roll the crypto. A missing signature, an invalid signature, or a
body without a payment id throws the shared `WebhookVerificationFailed` and never
becomes an event.

Because a Mollie webhook body carries only the payment id (`id=tr_…`), the verifier then
fetches the payment's authoritative state through the `PaymentFetcher` seam — its
status, our reference (from metadata), and the settled amount — and returns the engine's
gateway-agnostic `WebhookEvent`. Statuses map onto the narrow `WebhookEventType`: `paid`
→ `PaymentSettled`; `expired` / `canceled` / `failed` → `PaymentFailed`; every other
status (`open`, `pending`, `authorized`, …) → `PaymentPending` (recorded, but moving no
money).

Set the signing secret (with an API key, needed to fetch status) to bind the verifier:

```php
// .env
MOLLIE_WEBHOOK_SECRET=...
```

## 2. Exactly-once ingest (the engine's `WebhookIngest`)

`MollieWebhookHandler` is a thin composition — verify (and fetch), then ingest:

```php
$outcome = $handler->handle($payload); // $payload is a WebhookPayload built from the request
```

It hands the verified `WebhookEvent` to the engine's `WebhookIngest`, which applies the
paid effect **exactly once per invoice/payment reference** and returns an
`IngestOutcome` (`Applied`, `AlreadySettled`, `DuplicateEvent`, or `Ignored`). Three
guards live inside the shared ingest, not in this adapter:

- **Event-id dedup.** Mollie's classic webhook has no event id, so the first-sight key
  is the **payment id plus its current status** (e.g. `tr_123:paid`). A redelivery of
  the same state is a no-op, while a genuine transition (`open`→`paid`) is a distinct
  event and is processed.
- **Per-reference settle-once.** The paid effect is keyed on the invoice/payment
  reference, so two different payments about the same reference settle it once.
- **Crash-safe ordering.** The effect is applied before the settle claim and event id
  are recorded, so a crash mid-apply persists nothing and the redelivery re-applies
  exactly once.

A fetch failure propagates (as a Mollie exception) rather than being swallowed as a
verification failure, so the host can return a 5xx and let Mollie retry the delivery.

## 3. Durable stores the adapter contributes

The adapter overrides the engine's zero-config in-memory defaults with durable
database implementations of the shared `ProcessedEventStore` and `SettledPaymentStore`
(both enforced by a unique index via `insertOrIgnore`), so the ingest's guarantees hold
across processes and retries. Publish the migration with
`--tag=billing-mollie-migrations`.

The host binds its own `InvoicePaymentApplier` (the seam that writes the invoice's paid
state); in production that write commits in the same transaction as the settle claim.

## No-op backstop with the inline charge path

The inline `charge()` path and the webhook ingest share the same shared
`SettledPaymentStore`: `charge()` claims the reference the moment it succeeds, so a
webhook later confirming `paid` sees it is already settled and the ingest returns
`AlreadySettled`.

## Resume-safe charge

The inline charge is created with a scoped external idempotency key
(`reference:amount:currency`) passed to Mollie's `Idempotency-Key` header. If the
process crashes between the API call and recording the result, a retry with the same
key returns the original payment instead of creating a second one.
