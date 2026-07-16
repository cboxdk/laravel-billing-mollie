<?php

declare(strict_types=1);

use Cbox\Billing\Mollie\MollieApiWebhookVerifier;
use Cbox\Billing\Mollie\Testing\FakePaymentFetcher;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Payment\Enums\WebhookEventType;
use Cbox\Billing\Payment\Exceptions\WebhookVerificationFailed;
use Cbox\Billing\Payment\ValueObjects\WebhookPayload;
use Mollie\Api\Webhooks\SignatureValidator;

/**
 * Exercises the real Mollie SDK signature check ({@see SignatureValidator}) against a
 * genuinely-signed body — no mock over the crypto. The signature is computed exactly as
 * Mollie computes it (HMAC-SHA256 over the raw body). The fetcher is faked (a Mollie
 * webhook carries only the payment id, so the verifier fetches the authoritative state),
 * and we assert the normalisation onto the shared WebhookEvent.
 */
const SECRET = 'mollie_webhook_secret';

function verifier(string $status = 'paid', string $reference = 'DK-000001'): MollieApiWebhookVerifier
{
    return new MollieApiWebhookVerifier(
        new SignatureValidator(SECRET),
        new FakePaymentFetcher($status, $reference),
    );
}

function signedBody(string $paymentId = 'tr_live'): WebhookPayload
{
    $body = 'id='.$paymentId;
    $signature = hash_hmac('sha256', $body, SECRET);

    return new WebhookPayload($body, ['X-Mollie-Signature' => $signature]);
}

it('normalises a genuinely-signed paid payment onto the shared WebhookEvent', function () {
    $event = verifier('paid')->verify(signedBody('tr_live'));

    expect($event->id)->toBe('tr_live:paid')
        ->and($event->type)->toBe(WebhookEventType::PaymentSettled)
        ->and($event->reference)->toBe('DK-000001')
        ->and($event->amount)->toEqual(Money::ofMinor(12500, 'EUR'))
        ->and($event->isSettlement())->toBeTrue();
});

it('keys the dedup id on payment id plus status so an open→paid transition is distinct', function () {
    $open = verifier('open')->verify(signedBody('tr_x'));
    $paid = verifier('paid')->verify(signedBody('tr_x'));

    expect($open->id)->toBe('tr_x:open')
        ->and($paid->id)->toBe('tr_x:paid');
});

it('maps open and authorized to the SCA RequiresAction type (no effect)', function () {
    $open = verifier('open')->verify(signedBody('tr_a'));
    $authorized = verifier('authorized')->verify(signedBody('tr_b'));

    expect($open->type)->toBe(WebhookEventType::RequiresAction)
        ->and($open->type->requiresCustomerAction())->toBeTrue()
        ->and($open->isSettlement())->toBeFalse()
        ->and($authorized->type)->toBe(WebhookEventType::RequiresAction);
});

it('maps a pending status to the Processing type (no effect)', function () {
    $event = verifier('pending')->verify(signedBody('tr_p'));

    expect($event->type)->toBe(WebhookEventType::Processing)
        ->and($event->isSettlement())->toBeFalse();
});

it('maps a terminal status to a non-settling failure notice', function () {
    $event = verifier('expired')->verify(signedBody());

    expect($event->type)->toBe(WebhookEventType::PaymentFailed)
        ->and($event->isSettlement())->toBeFalse();
});

it('rejects a payload with no signature header (deny-by-default)', function () {
    verifier()->verify(new WebhookPayload('id=tr_live'));
})->throws(WebhookVerificationFailed::class);

it('rejects a tampered body whose signature no longer matches', function () {
    $payload = signedBody('tr_live');
    $tampered = new WebhookPayload('id=tr_tampered', $payload->headers);

    verifier()->verify($tampered);
})->throws(WebhookVerificationFailed::class);

it('rejects a signed body that carries no payment id', function () {
    $body = 'foo=bar';
    $signature = hash_hmac('sha256', $body, SECRET);

    verifier()->verify(new WebhookPayload($body, ['X-Mollie-Signature' => $signature]));
})->throws(WebhookVerificationFailed::class);
