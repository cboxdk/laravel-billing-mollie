<?php

declare(strict_types=1);

use Cbox\Billing\Mollie\MolliePaymentGateway;
use Cbox\Billing\Mollie\Testing\FakeMollieIntentCreator;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Payment\Enums\PaymentStatus;
use Cbox\Billing\Payment\ValueObjects\PaymentIntent;

function paymentIntent(): PaymentIntent
{
    return new PaymentIntent('pi_1', Money::ofMinor(12500, 'EUR'), 'DK-000001');
}

it('is named mollie', function () {
    expect((new MolliePaymentGateway(new FakeMollieIntentCreator))->name())->toBe('mollie');
});

it('maps a paid payment to a settled result', function () {
    $result = (new MolliePaymentGateway(new FakeMollieIntentCreator('paid', 'tr_live')))->charge(paymentIntent());

    expect($result->isSettled())->toBeTrue()
        ->and($result->gatewayReference)->toBe('tr_live');
});

it('maps open to pending (redirect-based flow)', function () {
    $result = (new MolliePaymentGateway(new FakeMollieIntentCreator('open')))->charge(paymentIntent());

    expect($result->status)->toBe(PaymentStatus::Pending);
});

it('turns an API failure into a failed result without throwing', function () {
    $result = (new MolliePaymentGateway(new FakeMollieIntentCreator(fail: true)))->charge(paymentIntent());

    expect($result->status)->toBe(PaymentStatus::Failed)
        ->and($result->failureReason)->toBe('payment_refused');
});

it('treats a canceled/expired status as failed', function () {
    expect((new MolliePaymentGateway(new FakeMollieIntentCreator('expired')))->charge(paymentIntent())->status)
        ->toBe(PaymentStatus::Failed);
});
