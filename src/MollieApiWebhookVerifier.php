<?php

declare(strict_types=1);

namespace Cbox\Billing\Mollie;

use Cbox\Billing\Mollie\Contracts\WebhookVerifier;
use Cbox\Billing\Mollie\Exceptions\WebhookVerificationFailed;
use Mollie\Api\Exceptions\InvalidSignatureException;
use Mollie\Api\Webhooks\SignatureValidator;

/**
 * The real webhook verifier: wraps the Mollie SDK's {@see SignatureValidator}
 * (HMAC-SHA256 over the raw body against the webhook signing secret) and extracts the
 * payment id from Mollie's form-encoded body (`id=tr_…`). No bespoke crypto —
 * verification is entirely the SDK's. Deny-by-default: an invalid signature, or a
 * body with no signature at all, is rejected. Verify against the live Mollie API
 * before relying on it in production.
 */
readonly class MollieApiWebhookVerifier implements WebhookVerifier
{
    public function __construct(private SignatureValidator $validator) {}

    public function verify(string $payload, string $signature): string
    {
        try {
            $valid = $this->validator->validatePayload($payload, $signature);
        } catch (InvalidSignatureException $e) {
            throw new WebhookVerificationFailed($e->getMessage(), previous: $e);
        }

        // validatePayload returns false for a legacy, unsigned delivery. We require a
        // signature, so an unsigned payload is refused rather than trusted.
        if (! $valid) {
            throw new WebhookVerificationFailed('Missing Mollie webhook signature.');
        }

        return $this->paymentId($payload);
    }

    private function paymentId(string $payload): string
    {
        parse_str($payload, $params);
        $id = $params['id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw new WebhookVerificationFailed('Mollie webhook payload is missing a payment id.');
        }

        return $id;
    }
}
