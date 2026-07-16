# Cbox Billing — Mollie

**`cboxdk/laravel-billing-mollie`** — a Mollie payment-gateway adapter for
[`cboxdk/laravel-billing`](https://github.com/cboxdk/laravel-billing). It implements
billing's `PaymentGateway` contract backed by Mollie.

## Install

```bash
composer require cboxdk/laravel-billing-mollie
```

```php
// .env
MOLLIE_KEY=live_...
MOLLIE_REDIRECT_URL=https://your-app.test/billing/return
```

With a key set, the provider binds `Cbox\Billing\Payment\Contracts\PaymentGateway`
to the Mollie gateway. Mollie is redirect-based, so a payment starts `open`
(pending) until the customer completes it and Mollie confirms `paid`.

## Design

- **SDK isolated behind a seam** (`MollieIntentCreator`): the real
  `MollieApiIntentCreator` wraps the Mollie SDK; a `FakeMollieIntentCreator` drives
  the tests — so status-mapping and error handling are unit-tested without the
  network.
- **Never throws.** An API failure becomes a failed `PaymentResult`; Mollie
  statuses map to `succeeded` (paid) / `pending` (open) / `requires_action`
  (authorized) / `failed`.
- **Customers and mandates.** `createCustomer()` mints a Mollie customer (`cst_…`),
  stamping the host's account key into its metadata so it reconciles from the Mollie
  dashboard; a customer that never reached Mollie is never returned. In Mollie a saved
  recurring "payment method" *is* a mandate, so `detachPaymentMethod($account,
  $paymentMethodId)` treats `$paymentMethodId` as a mandate id and `$account` as the
  owning customer reference, and **revokes** that mandate. The revoke is idempotent —
  an already-revoked or unknown mandate (Mollie 410 / 404) is a no-op.
- **Idempotent webhooks on the shared seam.** `MollieApiWebhookVerifier` implements
  billing's canonical `Cbox\Billing\Payment\Contracts\WebhookVerifier`: it proves the
  Mollie signature via the SDK (deny-by-default), fetches the payment's authoritative
  state through the `PaymentFetcher` seam (a Mollie webhook carries only the payment
  id), and normalises the delivery onto the engine's shared `WebhookEvent`.
  `MollieWebhookHandler` then hands that event to the engine's own `WebhookIngest`,
  which applies the paid effect to the invoice exactly once per reference — collapsing
  gateway re-deliveries and crash-retries. The adapter only overrides the shared
  dedup/settle stores with durable database implementations; it owns no webhook
  contracts of its own. Charges carry a scoped external idempotency key so a
  crash-and-retry never duplicates a payment. Set `MOLLIE_WEBHOOK_SECRET` to enable it.
  See [docs/core-concepts/webhooks.md](docs/core-concepts/webhooks.md).

> The SDK wrapper implements Mollie's documented API shape — verify against the live
> Mollie API before relying on it in production.

## Running the live integration tests

The default suite proves the gateway's mapping and error handling against the
`FakeMollieIntentCreator` — no network. A separate suite in
[`tests/Integration/MollieLiveTest.php`](tests/Integration/MollieLiveTest.php)
(Pest group `integration`) exercises the **real** Mollie API path
(`MollieApiIntentCreator` + `MolliePaymentGateway`) against **Mollie test mode**.

It is gated on a `test_…` key in `MOLLIE_TEST_KEY` — deliberately distinct from a
production `MOLLIE_KEY` so the two can never collide. Without the key the suite skips
cleanly, so `composer qa` / `vendor/bin/pest` stay green (skips reported) and the suite
is excluded from CI. No secret lives in the repo.

Run it explicitly:

```bash
MOLLIE_TEST_KEY=test_… vendor/bin/pest --group=integration
```

It hits Mollie **test mode only** (a `test_…` key forces test mode on every request, so
no live money moves), and it creates then revokes **throwaway** test objects — a stored
customer (`cst_…`) and a `directdebit` mandate (`mdt_…`) established headlessly with
Mollie's documented test IBAN — deleting the customer on teardown. What it covers
end-to-end, unattended:

- `createCustomer()` → a `cst_…` id with the host account stamped into
  `metadata.account`.
- a `directdebit` mandate created directly via the customer-mandates API (no redirect).
- `detachPaymentMethod()` revokes the mandate (it becomes `invalid`/gone), and calling
  it **again** is an idempotent no-op (Mollie 410/404) — also proven against a
  never-created mandate id.

**Manual step (not covered).** The `first`-sequence **setup-payment** path
(`createSetupIntent()`) returns a Mollie hosted-checkout URL a human must complete in a
browser to establish the mandate, so it cannot run unattended and is **not** exercised
here. The headless `directdebit`-mandate route above stands in for it to prove the
revoke/detach lifecycle; the redirect-completed setup flow must be verified manually.

## Requirements

PHP `^8.4`; Laravel `^12 || ^13`; `mollie/mollie-api-php` `^3.13`; `cboxdk/laravel-billing`.

## License

MIT.
