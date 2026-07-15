---
title: Quickstart
weight: 1
description: Install, set a Mollie key + redirect URL, and route billing payments through Mollie.
---

# Quickstart

```bash
composer require cboxdk/laravel-billing-mollie
```

```php
// .env
MOLLIE_KEY=live_...
MOLLIE_REDIRECT_URL=https://your-app.test/billing/return
```

With a key set, `Cbox\Billing\Payment\Contracts\PaymentGateway` resolves to the
Mollie gateway. Charge as usual through billing:

```php
use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Cbox\Billing\Payment\ValueObjects\PaymentIntent;
use Cbox\Billing\Money\Money;

$result = app(PaymentGateway::class)->charge(
    new PaymentIntent('pi_1', Money::ofMinor(12500, 'EUR'), 'DK-000001'),
);

$result->status;             // pending while the customer completes the redirect
$result->gatewayReference;   // the Mollie payment id, for reconciliation
```

Without a key the package stays out of the way and billing keeps its default
(manual) gateway.
