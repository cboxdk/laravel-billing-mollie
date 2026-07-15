<?php

declare(strict_types=1);

namespace Cbox\Billing\Mollie\Testing;

use Cbox\Billing\Mollie\Contracts\WebhookVerifier;
use Cbox\Billing\Mollie\Exceptions\WebhookVerificationFailed;

/**
 * A scripted {@see WebhookVerifier} for tests — returns a fixed payment id or, when
 * configured to reject, throws {@see WebhookVerificationFailed} to stand in for a bad
 * or missing signature. No SDK or crypto; the real verification is proven separately
 * against the live Mollie SDK.
 */
class FakeWebhookVerifier implements WebhookVerifier
{
    public function __construct(
        private string $paymentId = 'tr_fake',
        private bool $reject = false,
    ) {}

    public function verify(string $payload, string $signature): string
    {
        if ($this->reject) {
            throw new WebhookVerificationFailed('Invalid Mollie signature.');
        }

        return $this->paymentId;
    }
}
