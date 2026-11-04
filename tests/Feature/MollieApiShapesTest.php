<?php

declare(strict_types=1);

use Cbox\Billing\Mollie\Exceptions\MollieChargeFailed;
use Cbox\Billing\Mollie\Exceptions\MolliePaymentNotFound;
use Cbox\Billing\Mollie\MollieApiIntentCreator;
use Cbox\Billing\Mollie\MollieApiPaymentFetcher;
use Cbox\Billing\Mollie\MolliePaymentGateway;
use Cbox\Billing\Mollie\Tests\Support\MollieHttp;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Payment\Enums\PaymentStatus;
use Cbox\Billing\Payment\Testing\FakeSettledPaymentStore;
use Cbox\Billing\Payment\ValueObjects\PaymentIntent;

/**
 * The real SDK seams against Mollie's own documented responses (tests/Fixtures/api, copied from
 * the API reference at docs.mollie.com): the request we send, and what we read back.
 */
function creator(MollieHttp $http): MollieApiIntentCreator
{
    return new MollieApiIntentCreator(
        $http->client,
        redirectUrl: static fn (): string => 'https://billing.example/portal/return',
        webhookUrl: static fn (): string => 'https://billing.example/webhooks/mollie/production',
    );
}

it('reads a paid card payment: amount, our reference, customer, mandate and refunded total', function () {
    $payment = MollieHttp::fixture('payment.paid-creditcard');
    $payment['metadata'] = ['reference' => 'CBOX-2026-00042'];
    $http = (new MollieHttp)->respond($payment);

    $read = (new MollieApiPaymentFetcher($http->client))->fetch('tr_8KwCUNMVJe');

    expect($read->id)->toBe('tr_8KwCUNMVJe')
        ->and($read->status)->toBe('paid')
        ->and($read->reference)->toBe('CBOX-2026-00042')
        ->and($read->amount)->toEqual(Money::ofMinor(1, 'EUR'))
        ->and($read->customerId)->toBe('cst_77Ffsgn3ny')
        ->and($read->mandateId)->toBe('mdt_DPRgAhbMDG')
        ->and($read->method)->toBe('creditcard')
        ->and($read->sequenceType)->toBe('recurring')
        ->and($read->amountRefunded)->toEqual(Money::ofMinor(1, 'EUR'))
        ->and($read->chargebacks)->toBe([])
        ->and((string) $http->request(0)->getUri())->toBe('https://api.mollie.com/v2/payments/tr_8KwCUNMVJe?embed=chargebacks');
});

it('reads the card failure reason off a failed payment', function () {
    $read = (new MollieApiPaymentFetcher((new MollieHttp)->respond(MollieHttp::fixture('payment.failed-creditcard'))->client))->fetch('tr_EdTCRdcfCs');

    expect($read->status)->toBe('failed')->and($read->failureReason)->toBe('authentication_failed');
});

it('reads an embedded chargeback with its bank reason', function () {
    $payment = MollieHttp::fixture('payment.paid-creditcard');
    $payment['amountChargedBack'] = ['value' => '43.38', 'currency' => 'EUR'];
    $payment['_embedded'] = ['chargebacks' => [MollieHttp::fixture('chargeback')]];

    $read = (new MollieApiPaymentFetcher((new MollieHttp)->respond($payment)->client))->fetch('tr_8KwCUNMVJe');
    $chargeback = $read->latestChargeback();

    expect($read->amountChargedBack)->toEqual(Money::ofMinor(4338, 'EUR'))
        ->and($chargeback?->id)->toBe('chb_xFzwUN4ci8HAmSGUACS4J')
        ->and($chargeback?->amount)->toEqual(Money::ofMinor(4338, 'USD'))
        ->and($chargeback?->reasonCode)->toBe('AC01')
        ->and($chargeback?->reasonDescription)->toBe('Account identifier incorrect (i.e. invalid IBAN)')
        ->and($chargeback?->isReversed())->toBeFalse();
});

it('says a payment the account does not have is not found', function () {
    $http = (new MollieHttp)->error(404, 'Not Found', 'No payment exists with token tr_forged.');

    (new MollieApiPaymentFetcher($http->client))->fetch('tr_forged');
})->throws(MolliePaymentNotFound::class);

it('sends the webhook URL and the redirect on an on-session payment', function () {
    $http = (new MollieHttp)->respond(MollieHttp::fixture('payment.open'));

    $result = creator($http)->createIntent('10.00', 'EUR', 'cst_4qqhO89gsT', 'CBOX-1', 'key-1', null);
    $sent = $http->body(0);

    expect($sent['webhookUrl'])->toBe('https://billing.example/webhooks/mollie/production')
        ->and($sent['redirectUrl'])->toBe('https://billing.example/portal/return')
        ->and($sent['metadata'])->toBe(['reference' => 'CBOX-1'])
        ->and($sent)->not->toHaveKey('sequenceType')
        ->and($http->request(0)->getHeaderLine('Idempotency-Key'))->toBe('key-1')
        ->and($result['checkoutUrl'])->toBe('https://www.mollie.com/checkout/select-method/7UhSN1zuXS');
});

it('charges a saved mandate as a recurring payment with the webhook and no redirect', function () {
    $http = (new MollieHttp)->respond(MollieHttp::fixture('payment.recurring-directdebit'));

    $result = (new MolliePaymentGateway(creator($http), new FakeSettledPaymentStore))->charge(new PaymentIntent(
        id: 'pi_1',
        amount: Money::ofMinor(2000, 'EUR'),
        reference: 'CBOX-2',
        customerRef: 'cst_tKt44u85MM',
        paymentMethodRef: 'mdt_uDPFVsxjR4',
    ));
    $sent = $http->body(0);

    expect($sent['sequenceType'])->toBe('recurring')
        ->and($sent['mandateId'])->toBe('mdt_uDPFVsxjR4')
        ->and($sent['customerId'])->toBe('cst_tKt44u85MM')
        ->and($sent['amount'])->toBe(['currency' => 'EUR', 'value' => '20.00'])
        ->and($sent['webhookUrl'])->toBe('https://billing.example/webhooks/mollie/production')
        ->and($sent)->not->toHaveKey('redirectUrl')
        ->and($result->status)->toBe(PaymentStatus::Pending)
        ->and($result->gatewayReference)->toBe('tr_DjH8FpPNj7');
});

it('fails a recurring card charge with the reason Mollie gave', function () {
    $failed = MollieHttp::fixture('payment.failed-creditcard');
    $failed['details']['failureReason'] = 'insufficient_funds';
    $http = (new MollieHttp)->respond($failed);

    $result = (new MolliePaymentGateway(creator($http), new FakeSettledPaymentStore))->charge(new PaymentIntent(
        id: 'pi_2', amount: Money::ofMinor(1, 'EUR'), reference: 'CBOX-3', customerRef: 'cst_1', paymentMethodRef: 'mdt_1',
    ));

    expect($result->status)->toBe(PaymentStatus::Failed)
        ->and($result->failureReason)->toBe('insufficient_funds')
        ->and($result->gatewayReference)->toBe('tr_EdTCRdcfCs');
});

it('keeps the SDK error as the previous of a failed call, so a host can tell a timeout from a refusal', function () {
    $http = (new MollieHttp)->error(422, 'Unprocessable Entity', 'The mandate is invalid.');

    try {
        creator($http)->createIntent('10.00', 'EUR', 'cst_1', 'CBOX-4', 'key-4', 'mdt_1');
        $this->fail('expected a failure');
    } catch (MollieChargeFailed $e) {
        expect($e->getPrevious())->not->toBeNull()->and($e->getCode())->toBe(422);
    }
});

it('lists a SEPA Direct Debit mandate as sepa_debit with the IBAN tail, and leaves invalid mandates out', function () {
    $valid = MollieHttp::fixture('mandate.directdebit');
    $invalid = array_merge($valid, ['id' => 'mdt_invalid', 'status' => 'invalid']);
    $card = array_merge($valid, ['id' => 'mdt_card', 'method' => 'creditcard', 'details' => ['cardHolder' => 'John Doe', 'cardNumber' => '3240', 'cardExpiryDate' => '2030-01-01', 'cardLabel' => 'Visa', 'cardFingerprint' => 'd3290e932k02f']]);
    $http = (new MollieHttp)->respond(['count' => 3, '_embedded' => ['mandates' => [$invalid, $valid, $card]], '_links' => ['self' => ['href' => '...', 'type' => 'application/hal+json'], 'previous' => null, 'next' => null]]);

    $methods = creator($http)->listMethods('cst_4qqhO89gsT');

    expect($methods)->toBe([
        ['id' => 'mdt_h3gAaD5zP', 'brand' => 'sepa_debit', 'last4' => '0000', 'expMonth' => null, 'expYear' => null, 'isDefault' => true],
        ['id' => 'mdt_card', 'brand' => 'Visa', 'last4' => '3240', 'expMonth' => 1, 'expYear' => 2030, 'isDefault' => false],
    ]);
});

it('sets up a mandate with a first payment carrying the webhook', function () {
    $http = (new MollieHttp)->respond(MollieHttp::fixture('payment.first'));

    $result = creator($http)->createSetup('cst_tKt44u85MM', 'setup-1');
    $sent = $http->body(0);

    expect($sent['sequenceType'])->toBe('first')
        ->and($sent['amount'])->toBe(['currency' => 'EUR', 'value' => '0.00'])
        ->and($sent['webhookUrl'])->toBe('https://billing.example/webhooks/mollie/production')
        ->and($result['checkoutUrl'])->toBe('https://www.mollie.com/checkout/test-mode?method=creditcard&token=3.ivicl6');
});
