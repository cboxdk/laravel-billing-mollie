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
- **Idempotent webhooks.** `MollieWebhookHandler` verifies the signature (via the
  SDK — deny-by-default), fetches the payment's status, dedups on payment id + status,
  settles each reference at most once, and no-ops when the inline path already settled
  it. Charges carry a scoped external idempotency key so a crash-and-retry never
  duplicates a payment. Set `MOLLIE_WEBHOOK_SECRET` to enable it. See
  [docs/core-concepts/webhooks.md](docs/core-concepts/webhooks.md).

> The SDK wrapper implements Mollie's documented API shape — verify against the live
> Mollie API before relying on it in production.

## Requirements

PHP `^8.4`; Laravel `^12 || ^13`; `mollie/mollie-api-php` `^3.13`; `cboxdk/laravel-billing`.

## License

MIT.
