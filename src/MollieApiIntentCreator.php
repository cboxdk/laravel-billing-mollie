<?php

declare(strict_types=1);

namespace Cbox\Billing\Mollie;

use Cbox\Billing\Mollie\Contracts\MollieIntentCreator;
use Cbox\Billing\Mollie\Exceptions\MollieChargeFailed;
use Mollie\Api\MollieApiClient;
use Throwable;

/**
 * The real Mollie-SDK-backed intent creator. Thin: it makes the API call and
 * normalises the result; decision logic lives in the gateway. Mollie is
 * redirect-based, so a created payment starts `open` (pending) until the customer
 * completes it. Verify against the live Mollie API before production.
 */
readonly class MollieApiIntentCreator implements MollieIntentCreator
{
    public function __construct(
        private MollieApiClient $client,
        private string $redirectUrl,
    ) {}

    public function create(string $amount, string $currency, string $reference): array
    {
        try {
            $payment = $this->client->payments->create([
                'amount' => ['currency' => $currency, 'value' => $amount],
                'description' => $reference,
                'redirectUrl' => $this->redirectUrl,
                'metadata' => ['reference' => $reference],
            ]);
        } catch (Throwable $e) {
            throw new MollieChargeFailed($e->getMessage(), previous: $e);
        }

        return [
            'id' => (string) $payment->id,
            'status' => (string) $payment->status,
        ];
    }
}
