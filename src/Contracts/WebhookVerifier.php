<?php

declare(strict_types=1);

namespace Cbox\Billing\Mollie\Contracts;

use Cbox\Billing\Mollie\Exceptions\WebhookVerificationFailed;
use Mollie\Api\Webhooks\SignatureValidator;

/**
 * The seam over Mollie's webhook signature verification. The real implementation
 * wraps the Mollie SDK's {@see SignatureValidator} (HMAC-SHA256
 * over the raw body against the webhook signing secret) — we never hand-roll the
 * crypto. A Mollie webhook body carries only the payment id; on success the verifier
 * returns it. Deny-by-default: an unverified or unsigned payload throws.
 */
interface WebhookVerifier
{
    /**
     * Verify the raw request body against the `X-Mollie-Signature` header and return
     * the Mollie payment id contained in the body.
     *
     * @throws WebhookVerificationFailed when the signature is invalid or absent
     */
    public function verify(string $payload, string $signature): string;
}
