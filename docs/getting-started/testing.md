---
title: Testing
weight: 2
description: Drive the gateway with the fake intent creator — no SDK, no network.
---

# Testing

Because the Mollie SDK sits behind the `MollieIntentCreator` seam, you test the
gateway with `FakeMollieIntentCreator` — no network, no keys:

```php
use Cbox\Billing\Mollie\MolliePaymentGateway;
use Cbox\Billing\Mollie\Testing\FakeMollieIntentCreator;

$gateway = new MolliePaymentGateway(new FakeMollieIntentCreator('paid', 'tr_test'));

$result = $gateway->charge($intent);
expect($result->isSettled())->toBeTrue();
```

Pass a status (`paid`, `open`, `authorized`, `expired`, …) or `fail: true` to
exercise each mapped outcome, including the never-throws failure path.
