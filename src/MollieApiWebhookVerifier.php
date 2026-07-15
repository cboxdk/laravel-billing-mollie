<?php

declare(strict_types=1);

namespace Cbox\Billing\Mollie;

use Cbox\Billing\Mollie\Contracts\PaymentFetcher;
use Cbox\Billing\Payment\Contracts\WebhookVerifier;
use Cbox\Billing\Payment\Enums\WebhookEventType;
use Cbox\Billing\Payment\Exceptions\WebhookVerificationFailed;
use Cbox\Billing\Payment\ValueObjects\WebhookEvent;
use Cbox\Billing\Payment\ValueObjects\WebhookPayload;
use Mollie\Api\Exceptions\InvalidSignatureException;
use Mollie\Api\Webhooks\SignatureValidator;

/**
 * The Mollie-backed {@see WebhookVerifier}: wraps the Mollie SDK's
 * {@see SignatureValidator} (HMAC-SHA256 over the raw body against the webhook signing
 * secret) and normalises the delivery onto the engine's gateway-agnostic
 * {@see WebhookEvent}. No bespoke crypto — verification is entirely the SDK's.
 *
 * A Mollie classic webhook body carries only the payment id (`id=tr_…`), never the
 * status or amount, so after proving the signature the verifier fetches the payment's
 * authoritative state through the {@see PaymentFetcher} seam to build the event. Mollie
 * has no native event id either, so the first-sight dedup key is the payment id plus its
 * current status (a genuine `open`→`paid` transition is a distinct event).
 *
 * Deny-by-default: a missing signature, an invalid signature, or a body without a
 * payment id throws {@see WebhookVerificationFailed} and never becomes an event. Verify
 * against the live Mollie API before relying on it in production.
 */
readonly class MollieApiWebhookVerifier implements WebhookVerifier
{
    public function __construct(
        private SignatureValidator $validator,
        private PaymentFetcher $fetcher,
    ) {}

    public function verify(WebhookPayload $payload): WebhookEvent
    {
        $signature = $payload->header('X-Mollie-Signature');

        if ($signature === null || $signature === '') {
            throw WebhookVerificationFailed::unsigned();
        }

        try {
            $valid = $this->validator->validatePayload($payload->body, $signature);
        } catch (InvalidSignatureException $e) {
            throw new WebhookVerificationFailed($e->getMessage(), previous: $e);
        }

        if (! $valid) {
            throw WebhookVerificationFailed::unsigned();
        }

        $payment = $this->fetcher->fetch($this->paymentId($payload->body));

        return new WebhookEvent(
            id: $payment['id'].':'.$payment['status'],
            type: self::mapType($payment['status']),
            reference: $payment['reference'],
            amount: $payment['amount'],
        );
    }

    /**
     * Map a Mollie payment status onto the engine's narrow event type. Only `paid`
     * carries the paid effect; a terminal `expired`/`canceled`/`failed` maps to a
     * failure notice; every other status (`open`, `pending`, `authorized`, …) maps to a
     * pending notice — recorded and deduped by the ingest, but moving no money.
     */
    private static function mapType(string $status): WebhookEventType
    {
        return match ($status) {
            'paid' => WebhookEventType::PaymentSettled,
            'expired', 'canceled', 'failed' => WebhookEventType::PaymentFailed,
            default => WebhookEventType::PaymentPending,
        };
    }

    private function paymentId(string $body): string
    {
        parse_str($body, $params);
        $id = $params['id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw new WebhookVerificationFailed('Mollie webhook payload is missing a payment id.');
        }

        return $id;
    }
}
