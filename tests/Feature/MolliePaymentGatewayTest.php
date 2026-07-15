<?php

declare(strict_types=1);

use Cbox\Billing\Mollie\MolliePaymentGateway;
use Cbox\Billing\Mollie\Testing\FakeMollieIntentCreator;
use Cbox\Billing\Mollie\Testing\FakeSettledPaymentStore;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Payment\Enums\PaymentStatus;
use Cbox\Billing\Payment\ValueObjects\PaymentIntent;

function paymentIntent(): PaymentIntent
{
    return new PaymentIntent('pi_1', Money::ofMinor(12500, 'EUR'), 'DK-000001');
}

function gateway(FakeMollieIntentCreator $creator, ?FakeSettledPaymentStore $settled = null): MolliePaymentGateway
{
    return new MolliePaymentGateway($creator, $settled ?? new FakeSettledPaymentStore);
}

it('is named mollie', function () {
    expect(gateway(new FakeMollieIntentCreator)->name())->toBe('mollie');
});

it('maps a paid payment to a settled result', function () {
    $result = gateway(new FakeMollieIntentCreator('paid', 'tr_live'))->charge(paymentIntent());

    expect($result->isSettled())->toBeTrue()
        ->and($result->gatewayReference)->toBe('tr_live');
});

it('maps open to pending (redirect-based flow)', function () {
    $result = gateway(new FakeMollieIntentCreator('open'))->charge(paymentIntent());

    expect($result->status)->toBe(PaymentStatus::Pending);
});

it('turns an API failure into a failed result without throwing', function () {
    $result = gateway(new FakeMollieIntentCreator(fail: true))->charge(paymentIntent());

    expect($result->status)->toBe(PaymentStatus::Failed)
        ->and($result->failureReason)->toBe('payment_refused');
});

it('treats a canceled/expired status as failed', function () {
    expect(gateway(new FakeMollieIntentCreator('expired'))->charge(paymentIntent())->status)
        ->toBe(PaymentStatus::Failed);
});

it('passes a scoped external idempotency key derived from reference, amount and currency', function () {
    $creator = new FakeMollieIntentCreator('open');

    gateway($creator)->charge(paymentIntent());

    expect($creator->idempotencyKeys)->toBe(['cbx-DK-000001-12500-EUR']);
});

it('records the reference as settled on a paid charge (webhook backstop)', function () {
    $settled = new FakeSettledPaymentStore;

    gateway(new FakeMollieIntentCreator('paid'), $settled)->charge(paymentIntent());

    expect($settled->isSettled('DK-000001'))->toBeTrue();
});

it('does not record settlement when the charge is not settled', function () {
    $settled = new FakeSettledPaymentStore;

    gateway(new FakeMollieIntentCreator('open'), $settled)->charge(paymentIntent());

    expect($settled->isSettled('DK-000001'))->toBeFalse();
});
