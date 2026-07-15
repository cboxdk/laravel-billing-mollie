---
title: Testing
weight: 2
description: Drive the gateway with the fake intent creator and dogfood the shared webhook fakes — no SDK, no network.
---

# Testing

## The gateway

Because the Mollie SDK sits behind the `MollieIntentCreator` seam, you test the
gateway with `FakeMollieIntentCreator` — no network, no keys:

```php
use Cbox\Billing\Mollie\MolliePaymentGateway;
use Cbox\Billing\Mollie\Testing\FakeMollieIntentCreator;
use Cbox\Billing\Payment\Testing\FakeSettledPaymentStore;

$gateway = new MolliePaymentGateway(
    new FakeMollieIntentCreator('paid', 'tr_test'),
    new FakeSettledPaymentStore,
);

$result = $gateway->charge($intent);
expect($result->isSettled())->toBeTrue();
```

Pass a status (`paid`, `open`, `authorized`, `expired`, …) or `fail: true` to
exercise each mapped outcome, including the never-throws failure path, and the refund
path.

## Webhooks — dogfood the shared seam

The webhook path is tested with the fakes the engine ships in
`Cbox\Billing\Payment\Testing`, so your test drives the very same exactly-once ingest
production uses. `FakeWebhookVerifier` stands in for the SDK signature check plus
payment fetch, and the real `DefaultWebhookIngest` runs over the shared in-memory
stores:

```php
use Cbox\Billing\Mollie\MollieWebhookHandler;
use Cbox\Billing\Payment\Testing\FakeInvoicePaymentApplier;
use Cbox\Billing\Payment\Testing\FakeProcessedEventStore;
use Cbox\Billing\Payment\Testing\FakeSettledPaymentStore;
use Cbox\Billing\Payment\Testing\FakeWebhookVerifier;
use Cbox\Billing\Payment\Webhook\DefaultWebhookIngest;

$applier = new FakeInvoicePaymentApplier;
$handler = new MollieWebhookHandler(
    FakeWebhookVerifier::accepting($event),
    new DefaultWebhookIngest(new FakeProcessedEventStore, new FakeSettledPaymentStore, $applier),
);

$outcome = $handler->handle($payload);
expect($outcome->wasApplied())->toBeTrue()
    ->and($applier->timesPaid($event->reference))->toBe(1);
```

The Mollie-specific `MollieApiWebhookVerifier` (signature check, payment-id extraction,
status → `WebhookEvent` normalisation) is proven against a genuinely-signed body through
the real Mollie SDK — see `tests/Feature/MollieApiWebhookVerifierTest.php`.
