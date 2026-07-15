---
title: Installation
weight: 1
description: Install via Composer and set the Mollie key and redirect URL.
---

# Installation

```bash
composer require cboxdk/laravel-billing-mollie
```

`MollieServiceProvider` is auto-discovered. Set a key and a redirect URL (Mollie is
redirect-based, so the customer returns there after paying):

```php
// .env
MOLLIE_KEY=live_...
MOLLIE_REDIRECT_URL=https://your-app.test/billing/return
```

Publish the config to change it:

```bash
php artisan vendor:publish --tag=billing-mollie-config
```

When the key is present, `Cbox\Billing\Payment\Contracts\PaymentGateway` is bound to
`MolliePaymentGateway`. When it is absent, the binding is skipped and billing keeps
its default gateway.
