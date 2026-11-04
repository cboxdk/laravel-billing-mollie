<?php

declare(strict_types=1);

use Cbox\Billing\Mollie\Exceptions\MollieChargeFailed;
use Cbox\Billing\Mollie\MollieApiPaymentFetcher;
use Cbox\Billing\Mollie\MollieApiWebhookVerifier;
use Cbox\Billing\Mollie\Testing\FakePaymentFetcher;
use Cbox\Billing\Mollie\Tests\Support\MollieHttp;
use Cbox\Billing\Mollie\ValueObjects\MollieChargeback;
use Cbox\Billing\Mollie\ValueObjects\MollieWebhookEvent;
use Cbox\Billing\Money\Money;
use Cbox\Billing\Payment\Enums\PaymentStatus;
use Cbox\Billing\Payment\Enums\WebhookEventType;
use Cbox\Billing\Payment\Exceptions\WebhookVerificationFailed;
use Cbox\Billing\Payment\ValueObjects\WebhookPayload;
use Mollie\Api\Webhooks\SignatureValidator;

/**
 * Mollie's classic payment webhook is a form POST of the payment id alone, unsigned; the
 * verifier proves it by fetching the payment with the account's key. A next-gen webhook is JSON,
 * signed with `X-Mollie-Signature: sha256=…`, checked by the real SDK validator (no mock over the
 * crypto). Either way the event is built only from the fetched payment.
 */
const SECRET = 'mollie_webhook_secret';

function verifier(FakePaymentFetcher $fetcher = new FakePaymentFetcher, bool $withSecret = true): MollieApiWebhookVerifier
{
    return new MollieApiWebhookVerifier($withSecret ? new SignatureValidator(SECRET) : null, $fetcher);
}

function classic(string $paymentId = 'tr_live'): WebhookPayload
{
    return new WebhookPayload('id='.$paymentId, ['Content-Type' => 'application/x-www-form-urlencoded']);
}

function nextGen(string $paymentId = 'tr_live', ?string $secret = SECRET): WebhookPayload
{
    $body = (string) json_encode(['resource' => 'event', 'id' => 'event_GvJ8WHrp5isUdRub9CJyH', 'type' => 'payment.paid', 'entityId' => $paymentId, 'createdAt' => '2025-01-01T10:00:00+00:00']);

    return new WebhookPayload($body, $secret === null ? [] : ['X-Mollie-Signature' => 'sha256='.hash_hmac('sha256', $body, $secret)]);
}

it('verifies a classic unsigned webhook by fetching the payment with the account key', function () {
    $fetcher = new FakePaymentFetcher('paid');

    $event = verifier($fetcher)->verify(classic('tr_live'));

    expect($fetcher->fetched)->toBe(['tr_live'])
        ->and($event)->toBeInstanceOf(MollieWebhookEvent::class)
        ->and($event->type)->toBe(WebhookEventType::PaymentSettled)
        ->and($event->reference)->toBe('DK-000001')
        ->and($event->amount)->toEqual(Money::ofMinor(12500, 'EUR'))
        ->and($event->toPaymentResult()->gatewayReference)->toBe('tr_live');
});

it('verifies a classic webhook without any signing secret configured', function () {
    expect(verifier(new FakePaymentFetcher('paid'), withSecret: false)->verify(classic())->isSettlement())->toBeTrue();
});

it('verifies a signed next-gen webhook and still fetches the payment', function () {
    $fetcher = new FakePaymentFetcher('paid');

    $event = verifier($fetcher)->verify(nextGen('tr_ng'));

    expect($fetcher->fetched)->toBe(['tr_ng'])->and($event->isSettlement())->toBeTrue();
});

it('refuses a next-gen webhook whose signature does not match, before any fetch', function () {
    $fetcher = new FakePaymentFetcher('paid');

    expect(fn () => verifier($fetcher)->verify(nextGen('tr_ng', 'another-secret')))->toThrow(WebhookVerificationFailed::class)
        ->and($fetcher->fetched)->toBe([]);
});

it('refuses a signed webhook when no signing secret is configured to check it', function () {
    verifier(withSecret: false)->verify(nextGen());
})->throws(WebhookVerificationFailed::class);

it('refuses a payment the account does not have, so a forged id settles nothing', function () {
    verifier(new FakePaymentFetcher(unknown: ['tr_forged']))->verify(classic('tr_forged'));
})->throws(WebhookVerificationFailed::class, 'Mollie has no such payment for this account.');

it('refuses a body that names no payment', function (string $body) {
    verifier()->verify(new WebhookPayload($body));
})->with(['foo=bar', 'id=', 'id=re_4qqhO89gsT', 'id=tr_../../v2/refunds', '{"entityId":"pl_4Y0eZitmBnQ6IDoMqZQKh"}'])
    ->throws(WebhookVerificationFailed::class);

it('lets a failure to reach Mollie propagate so the host answers 5xx and Mollie delivers again', function () {
    $http = (new MollieHttp)->error(503, 'Service Unavailable', 'The service is temporarily unavailable.');

    (new MollieApiWebhookVerifier(null, new MollieApiPaymentFetcher($http->client)))->verify(classic());
})->throws(MollieChargeFailed::class);

it('maps every Mollie status onto the engine vocabulary', function (string $status, WebhookEventType $type) {
    expect(verifier(new FakePaymentFetcher($status))->verify(classic())->type)->toBe($type);
})->with([
    ['paid', WebhookEventType::PaymentSettled],
    ['failed', WebhookEventType::PaymentFailed],
    ['expired', WebhookEventType::PaymentFailed],
    ['canceled', WebhookEventType::PaymentFailed],
    ['open', WebhookEventType::RequiresAction],
    ['authorized', WebhookEventType::RequiresAction],
    ['pending', WebhookEventType::Processing],
]);

it('carries the failure reason on a failed payment', function () {
    $result = verifier(new FakePaymentFetcher('failed', failureReason: 'insufficient_funds'))->verify(classic('tr_f'))->toPaymentResult();

    expect($result->status)->toBe(PaymentStatus::Failed)
        ->and($result->failureReason)->toBe('insufficient_funds')
        ->and($result->gatewayReference)->toBe('tr_f');
});

it('keys the event on the payment state, so a refund or chargeback on a paid payment is a new event', function () {
    $paid = verifier(new FakePaymentFetcher('paid'))->verify(classic('tr_x'));
    $again = verifier(new FakePaymentFetcher('paid'))->verify(classic('tr_x'));
    $refunded = verifier(new FakePaymentFetcher('paid', refunded: Money::ofMinor(500, 'EUR')))->verify(classic('tr_x'));
    $chargeback = new MollieChargeback('chb_1', Money::ofMinor(12500, 'EUR'), 'AC01', 'Account identifier incorrect', new DateTimeImmutable('2023-03-14T17:09:02+00:00'), null);
    $chargedBack = verifier(new FakePaymentFetcher('paid', chargebacks: [$chargeback]))->verify(classic('tr_x'));
    $reversed = new MollieChargeback('chb_1', Money::ofMinor(12500, 'EUR'), 'AC01', 'Account identifier incorrect', new DateTimeImmutable('2023-03-14T17:09:02+00:00'), new DateTimeImmutable('2023-03-20T10:00:00+00:00'));
    $won = verifier(new FakePaymentFetcher('paid', chargebacks: [$reversed]))->verify(classic('tr_x'));

    expect($paid->id)->toBe($again->id)
        ->and(array_unique([$paid->id, $refunded->id, $chargedBack->id, $won->id]))->toHaveCount(4)
        ->and($chargedBack instanceof MollieWebhookEvent ? $chargedBack->payment->latestChargeback()?->id : null)->toBe('chb_1');
});
