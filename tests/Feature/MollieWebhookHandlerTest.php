<?php

declare(strict_types=1);

use Cbox\Billing\Mollie\Exceptions\WebhookVerificationFailed;
use Cbox\Billing\Mollie\MollieWebhookHandler;
use Cbox\Billing\Mollie\Testing\FakePaymentFetcher;
use Cbox\Billing\Mollie\Testing\FakeProcessedEventStore;
use Cbox\Billing\Mollie\Testing\FakeSettledPaymentStore;
use Cbox\Billing\Mollie\Testing\FakeWebhookVerifier;
use Cbox\Billing\Payment\Enums\PaymentStatus;

function handler(
    string $paymentId = 'tr_1',
    string $status = 'paid',
    string $reference = 'DK-000001',
    ?FakeProcessedEventStore $processed = null,
    ?FakeSettledPaymentStore $settled = null,
    bool $reject = false,
): MollieWebhookHandler {
    return new MollieWebhookHandler(
        new FakeWebhookVerifier($paymentId, $reject),
        new FakePaymentFetcher($status, $reference),
        $processed ?? new FakeProcessedEventStore,
        $settled ?? new FakeSettledPaymentStore,
    );
}

it('rejects an unverified payload (deny-by-default)', function () {
    handler(reject: true)->handle('id=tr_1', 'bad-sig');
})->throws(WebhookVerificationFailed::class);

it('maps a verified paid payment to a settled result', function () {
    $result = handler(paymentId: 'tr_live', status: 'paid')->handle('id=tr_live', 'sig');

    expect($result)->not->toBeNull()
        ->and($result->isSettled())->toBeTrue()
        ->and($result->gatewayReference)->toBe('tr_live');
});

it('dedups a replayed delivery of the same payment+status to a no-op', function () {
    $processed = new FakeProcessedEventStore;

    $first = handler(processed: $processed)->handle('id=tr_1', 'sig');
    $second = handler(processed: $processed)->handle('id=tr_1', 'sig');

    expect($first)->not->toBeNull()
        ->and($second)->toBeNull();
});

it('processes a genuine state change (open then paid) for the same payment id', function () {
    $processed = new FakeProcessedEventStore;

    $open = handler(status: 'open', processed: $processed)->handle('id=tr_1', 'sig');
    $paid = handler(status: 'paid', processed: $processed)->handle('id=tr_1', 'sig');

    expect($open->status)->toBe(PaymentStatus::Pending)
        ->and($paid->isSettled())->toBeTrue();
});

it('settles a reference only once across different payments (per-reference idempotency)', function () {
    $settled = new FakeSettledPaymentStore;

    $first = handler(paymentId: 'tr_a', reference: 'DK-42', settled: $settled)->handle('id=tr_a', 'sig');
    $second = handler(paymentId: 'tr_b', reference: 'DK-42', settled: $settled)->handle('id=tr_b', 'sig');

    expect($first)->not->toBeNull()
        ->and($second)->toBeNull();
});

it('is a no-op when the inline path already settled the reference (backstop)', function () {
    $settled = new FakeSettledPaymentStore;
    $settled->markSettled('DK-000001');

    expect(handler(settled: $settled)->handle('id=tr_1', 'sig'))->toBeNull();
});

it('returns a non-settle result without touching the settle guard', function () {
    $settled = new FakeSettledPaymentStore;

    $result = handler(status: 'open', settled: $settled)->handle('id=tr_1', 'sig');

    expect($result->status)->toBe(PaymentStatus::Pending)
        ->and($settled->isSettled('DK-000001'))->toBeFalse();
});
