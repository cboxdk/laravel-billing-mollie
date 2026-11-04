---
title: Idempotent webhooks
weight: 2
description: How Mollie's classic and signed webhooks are proved, read and applied once on the shared billing seam.
---

# Idempotent webhooks

Mollie retries webhook deliveries and calls again on every change to a payment, so the same
effect must never be applied twice (no double-settle). This adapter carries no webhook
contracts of its own: it plugs into the **canonical webhook seam** shipped by
`cboxdk/laravel-billing`, contributing the Mollie-specific proof, a payment fetch, and a pair
of durable stores. The engine owns the exactly-once apply.

## 1. Proving a delivery (deny-by-default)

`MollieApiWebhookVerifier` implements the engine's shared
`Cbox\Billing\Payment\Contracts\WebhookVerifier`. Mollie sends two kinds of payment webhook,
and the verifier takes both:

- **Classic.** What a payment's `webhookUrl` receives: a form POST
  (`application/x-www-form-urlencoded`) whose only field is the payment id, `id=tr_…`. Mollie
  does not sign it. Its security model is that the body carries no state: the receiver fetches
  the payment from the API with its own key and acts on what the API says
  ([Mollie: Webhooks](https://docs.mollie.com/reference/webhooks)). A forged call can only make
  us look a payment up; it cannot claim one is paid. A payment id the account does not own is
  not found (HTTP 404), and the delivery is refused.
- **Next-gen.** A webhook subscription's `payment.*` event: JSON naming the payment in
  `entityId`, signed with `X-Mollie-Signature: sha256=<hmac>`, an HMAC-SHA256 of the raw body
  ([Mollie: Next-gen webhooks](https://docs.mollie.com/reference/webhooks-new)). With a signing
  secret configured, the SDK's `SignatureValidator` checks it before any API call. A signed
  delivery with no secret configured is refused. The payment is still fetched, because the
  fetch is what we act on.

Either way the event is built only from the fetched payment. A body that names no payment
(`tr_…`), a signature that does not match, or a payment the account does not have throws the
shared `WebhookVerificationFailed`. A failure to reach Mollie at all (a timeout, a 5xx)
propagates as `MollieChargeFailed`, so the host answers with a 5xx and Mollie delivers again
(Mollie retries ten times over about 26 hours).

The fetch is `GET /v2/payments/{id}?embed=chargebacks`, read into a `MolliePayment`: status,
our reference (from metadata), amount, customer, mandate, method, sequence type, the failure
reason (a card's `details.failureReason`, a direct debit's `details.bankReasonCode`), the
refunded and charged-back totals, and the chargebacks themselves.

The verifier returns a `MollieWebhookEvent`, the engine's `WebhookEvent` with the full
`MolliePayment` on `$payment`. Statuses map onto the narrow `WebhookEventType`: `paid` →
`PaymentSettled`; `expired` / `canceled` / `failed` → `PaymentFailed`; `open` / `authorized` →
`RequiresAction`; `pending` → `Processing`. A settlement settles under the Mollie payment id
(`tr_…`), the reference a refund takes.

Mollie calls the same webhook when a refund is processed and when a chargeback arrives, while
the payment stays `paid`. A host that wants to act on those reads `$event->payment`
(`amountRefunded`, `chargebacks`, `latestChargeback()`).

The API key alone binds the verifier; the signing secret is optional:

```php
// .env
MOLLIE_KEY=live_...
MOLLIE_WEBHOOK_URL=https://your-app.test/webhooks/mollie
MOLLIE_WEBHOOK_SECRET=...   # only for next-gen (signed) subscriptions
```

`MOLLIE_WEBHOOK_URL` is sent as the `webhookUrl` of every payment the adapter creates. Mollie
has no account-wide webhook for classic payments, so without it Mollie never calls back.

## 2. Exactly-once ingest (the engine's `WebhookIngest`)

`MollieWebhookHandler` is a thin composition — verify (and fetch), then ingest:

```php
$outcome = $handler->handle($payload); // $payload is a WebhookPayload built from the request
```

It hands the verified event to the engine's `WebhookIngest`, which applies the paid effect
**exactly once per invoice/payment reference** and returns an `IngestOutcome` (`Applied`,
`AlreadySettled`, `DuplicateEvent`, or `Ignored`). Three guards live inside the shared ingest,
not in this adapter:

- **Event-id dedup.** Mollie's classic webhook has no event id, so the first-sight key is the
  payment's **state**: its id, status, refunded total, charged-back total and chargebacks
  (e.g. `tr_123:paid:r0:c0:0.0`). A redelivery of the same state is a no-op, while a real
  change (`open`→`paid`, a refund, a chargeback, a reversal) is a distinct event.
- **Per-reference settle-once.** The paid effect is keyed on the invoice/payment reference,
  so two different payments about the same reference settle it once.
- **Crash-safe ordering.** The effect is applied before the settle claim and event id are
  recorded, so a crash mid-apply persists nothing and the redelivery re-applies exactly once.

## 3. Durable stores the adapter contributes

The adapter overrides the engine's zero-config in-memory defaults with durable database
implementations of the shared `ProcessedEventStore` and `SettledPaymentStore` (both enforced
by a unique index via `insertOrIgnore`), so the ingest's guarantees hold across processes and
retries. Publish the migration with `--tag=billing-mollie-migrations`.

The host binds its own `InvoicePaymentApplier` (the seam that writes the invoice's paid
state); in production that write commits in the same transaction as the settle claim.

## No-op backstop with the inline charge path

The inline `charge()` path and the webhook ingest share the same `SettledPaymentStore`:
`charge()` claims the reference the moment it succeeds, so a webhook later confirming `paid`
sees it is already settled and the ingest returns `AlreadySettled`.

## Resume-safe charge

The inline charge is created with a scoped external idempotency key
(`reference:amount:currency`) passed to Mollie's `Idempotency-Key` header. If the process
crashes between the API call and recording the result, a retry with the same key returns the
original payment instead of creating a second one.
