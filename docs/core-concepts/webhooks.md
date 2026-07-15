---
title: Idempotent webhooks
weight: 2
description: How replayed and retried Mollie webhooks are verified once and applied once.
---

# Idempotent webhooks

Mollie retries webhook deliveries and re-notifies on every state change, so the same
effect must never be applied twice (no double-settle). Because a Mollie webhook body
carries only the payment id, `MollieWebhookHandler` first fetches the payment's
authoritative status, then makes the path idempotent through four layers.

## 1. Signature verification (deny-by-default)

`WebhookVerifier` is the seam over Mollie's signature check. The real
`MollieApiWebhookVerifier` wraps the SDK's `SignatureValidator` — HMAC-SHA256 over the
raw body against the webhook signing secret. We never hand-roll the crypto. An
unsigned or invalid payload throws `WebhookVerificationFailed` and never touches
state. On success the verifier returns the payment id parsed from the body.

Set the signing secret (with an API key, needed to fetch status) to enable the handler:

```php
// .env
MOLLIE_WEBHOOK_SECRET=...
```

## 2. Event-id dedup

Mollie's classic webhook has no event id, so the stable identity is the **payment id
plus its current status** (e.g. `tr_123:paid`). `ProcessedEventStore` records
processed ids behind a unique index (`insertOrIgnore`): a redelivery of the same state
is a no-op, while a genuine transition (`open`→`paid`) is a distinct event and is
processed. `FakeProcessedEventStore` backs the tests.

## 3. Per-reference settle-once

The settle effect is keyed on the invoice/payment **reference** (read from the
payment's metadata), not the event, via `SettledPaymentStore`. A different payment
about the same reference cannot settle it a second time.

## 4. No-op backstop

The inline `charge()` path and the webhook path share the same `SettledPaymentStore`:
`charge()` records the reference as settled the moment it succeeds, so a webhook later
confirming `paid` sees it is already settled and does nothing.

## Resume-safe charge

The inline charge is created with a scoped external idempotency key
(`reference:amount:currency`) passed to Mollie's `Idempotency-Key` header. If the
process crashes between the API call and recording the result, a retry with the same
key returns the original payment instead of creating a second one.

## Applying the result

`handle()` returns the mapped `PaymentResult` for the host to apply, or `null` when
the delivery is a duplicate or not actionable. A fetch failure propagates so the host
can return a 5xx and let Mollie retry. The stores default to durable database
implementations (publish the migration with `--tag=billing-mollie-migrations`) so the
guarantees hold across processes and retries.
