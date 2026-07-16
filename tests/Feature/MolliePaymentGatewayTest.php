<?php

declare(strict_types=1);

use Cbox\Billing\Mollie\Exceptions\MollieChargeFailed;
use Cbox\Billing\Mollie\MolliePaymentGateway;
use Cbox\Billing\Mollie\Testing\FakeMollieIntentCreator;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Payment\Enums\PaymentIntentStatus;
use Cbox\Billing\Payment\Enums\PaymentStatus;
use Cbox\Billing\Payment\Testing\FakeSettledPaymentStore;
use Cbox\Billing\Payment\ValueObjects\PaymentIntent;
use Cbox\Billing\Payment\ValueObjects\PaymentIntentRequest;
use Cbox\Billing\Payment\ValueObjects\RefundIntent;
use Cbox\Billing\Payment\ValueObjects\SetupIntentRequest;

function paymentIntent(): PaymentIntent
{
    return new PaymentIntent('pi_1', Money::ofMinor(12500, 'EUR'), 'DK-000001');
}

function refundIntent(): RefundIntent
{
    return new RefundIntent('cn_1', Money::ofMinor(12500, 'EUR'), 'CN-000001', 'cbx-refund-CN-000001', 'tr_live');
}

function gateway(FakeMollieIntentCreator $creator, ?FakeSettledPaymentStore $settled = null, string $profileId = 'pfl_test123'): MolliePaymentGateway
{
    return new MolliePaymentGateway($creator, $settled ?? new FakeSettledPaymentStore, $profileId);
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

it('maps a refunded refund to a settled result and passes the scoped idempotency key', function () {
    $creator = new FakeMollieIntentCreator;

    $result = gateway($creator)->refund(refundIntent());

    expect($result->isSettled())->toBeTrue()
        ->and($result->gatewayReference)->toBe('re_fake')
        ->and($creator->refundIdempotencyKeys)->toBe(['cbx-refund-CN-000001']);
});

it('turns a refund API failure into a failed result without throwing', function () {
    $result = gateway(new FakeMollieIntentCreator(fail: true))->refund(refundIntent());

    expect($result->status)->toBe(PaymentStatus::Failed)
        ->and($result->failureReason)->toBe('refund_failed');
});

it('creates an on-session payment intent shaped for the frontend (open maps to RequiresAction)', function () {
    $creator = new FakeMollieIntentCreator;
    $request = new PaymentIntentRequest('cst_1', 'DK-000001', Money::ofMinor(12500, 'EUR'), 'idem-pi-1');

    $result = gateway($creator)->createPaymentIntent($request);

    expect($result->gateway)->toBe('mollie')
        ->and($result->publishableKey)->toBe('pfl_test123')
        ->and($result->clientSecret)->toBe('https://checkout.mollie.test/idem-pi-1')
        ->and($result->status)->toBe(PaymentIntentStatus::RequiresAction)
        ->and($result->requiresCustomerAction())->toBeTrue()
        ->and($result->reference)->toBe('DK-000001')
        ->and($result->amount)->toEqual(Money::ofMinor(12500, 'EUR'))
        ->and($creator->intentIdempotencyKeys)->toBe(['idem-pi-1'])
        ->and($creator->intentMandateIds)->toBe([null]);
});

it('charges a saved mandate off-session when a payment method is given (paid maps to Succeeded)', function () {
    $creator = new FakeMollieIntentCreator(intentStatus: 'paid');
    $request = new PaymentIntentRequest('cst_1', 'DK-000001', Money::ofMinor(12500, 'EUR'), 'idem-pi-2', 'mdt_saved');

    $result = gateway($creator)->createPaymentIntent($request);

    expect($result->status)->toBe(PaymentIntentStatus::Succeeded)
        ->and($creator->intentMandateIds)->toBe(['mdt_saved']);
});

it('omits the publishable key when no profile id is configured', function () {
    $request = new PaymentIntentRequest('cst_1', 'DK-000001', Money::ofMinor(12500, 'EUR'), 'idem-pi-3');

    $result = gateway(new FakeMollieIntentCreator, profileId: '')->createPaymentIntent($request);

    expect($result->publishableKey)->toBeNull();
});

it('creates an off-session setup that establishes a mandate for renewals', function () {
    $creator = new FakeMollieIntentCreator;
    $request = new SetupIntentRequest('cst_1', 'idem-seti-1');

    $result = gateway($creator)->createSetupIntent($request);

    expect($result->gateway)->toBe('mollie')
        ->and($result->publishableKey)->toBe('pfl_test123')
        ->and($result->clientSecret)->toBe('https://checkout.mollie.test/setup/idem-seti-1')
        ->and($result->reference)->toBe('tr_setup')
        ->and($creator->setupIdempotencyKeys)->toBe(['idem-seti-1']);
});

it('creates a Mollie customer, returns the cst_ reference and stamps the account into metadata', function () {
    $creator = new FakeMollieIntentCreator;

    $reference = gateway($creator)->createCustomer('DK-000001', 'ada@example.test', 'Ada Lovelace');

    expect($reference)->toBe('cst_test_DK-000001')
        ->and($creator->customerMetadata['DK-000001'])->toBe([
            'email' => 'ada@example.test',
            'name' => 'Ada Lovelace',
            'account' => 'DK-000001',
        ]);
});

it('resolves the same customer reference when created again for the same account', function () {
    $creator = new FakeMollieIntentCreator;
    $gw = gateway($creator);

    $first = $gw->createCustomer('DK-000001', 'ada@example.test', 'Ada Lovelace');
    $second = $gw->createCustomer('DK-000001');

    expect($second)->toBe($first)
        ->and($creator->customers)->toHaveCount(1);
});

it('surfaces an SDK failure on customer creation rather than returning an uncreated customer', function () {
    expect(fn () => gateway(new FakeMollieIntentCreator(fail: true))->createCustomer('DK-000001'))
        ->toThrow(MollieChargeFailed::class);
});

it('detaches a payment method by revoking the mandate for the customer', function () {
    $creator = new FakeMollieIntentCreator;
    $gw = gateway($creator);

    $gw->attachPaymentMethod('cst_1', 'mdt_a');
    $gw->attachPaymentMethod('cst_1', 'mdt_b');

    $gw->detachPaymentMethod('cst_1', 'mdt_a');

    expect($creator->isMandateRevoked('cst_1', 'mdt_a'))->toBeTrue()
        ->and(collect($gw->paymentMethods('cst_1'))->pluck('id')->all())->toBe(['mdt_b']);
});

it('is idempotent: detaching an already-revoked mandate repeats as a no-op without erroring', function () {
    $creator = new FakeMollieIntentCreator;
    $gw = gateway($creator);

    $gw->attachPaymentMethod('cst_1', 'mdt_a');

    $gw->detachPaymentMethod('cst_1', 'mdt_a');
    $gw->detachPaymentMethod('cst_1', 'mdt_a');

    expect($gw->paymentMethods('cst_1'))->toBe([])
        ->and($creator->revokedMandates)->toBe([
            ['customerId' => 'cst_1', 'mandateId' => 'mdt_a'],
            ['customerId' => 'cst_1', 'mandateId' => 'mdt_a'],
        ]);
});

it('attaches a mandate, lists it, and makes it the default', function () {
    $gw = gateway(new FakeMollieIntentCreator);

    expect($gw->paymentMethods('cst_1'))->toBe([]);

    $first = $gw->attachPaymentMethod('cst_1', 'mdt_a');
    $second = $gw->attachPaymentMethod('cst_1', 'mdt_b');

    expect($first->id)->toBe('mdt_a')
        ->and($first->brand)->toBe('Visa')
        ->and($first->last4)->toBe('4242')
        ->and($first->isDefault)->toBeTrue()
        ->and($second->isDefault)->toBeFalse()
        ->and($gw->paymentMethods('cst_1'))->toHaveCount(2);

    $gw->setDefaultPaymentMethod('cst_1', 'mdt_b');

    $byId = collect($gw->paymentMethods('cst_1'))->keyBy->id;

    expect($byId['mdt_a']->isDefault)->toBeFalse()
        ->and($byId['mdt_b']->isDefault)->toBeTrue();
});
