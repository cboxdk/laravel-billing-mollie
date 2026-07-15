<?php

declare(strict_types=1);

namespace Cbox\Billing\Mollie;

use Cbox\Billing\Mollie\Contracts\PaymentFetcher;
use Cbox\Billing\Mollie\Exceptions\MollieChargeFailed;
use Mollie\Api\MollieApiClient;
use Throwable;

/**
 * The real payment fetcher: wraps {@see MollieApiClient} to read a payment's current
 * status and our reference (from its metadata) by id. Thin by design — the handler
 * owns the idempotency and status-mapping logic. Verify against the live Mollie API
 * before relying on it in production.
 */
readonly class MollieApiPaymentFetcher implements PaymentFetcher
{
    public function __construct(private MollieApiClient $client) {}

    public function fetch(string $paymentId): array
    {
        try {
            $payment = $this->client->payments->get($paymentId);
        } catch (Throwable $e) {
            throw new MollieChargeFailed($e->getMessage(), previous: $e);
        }

        return [
            'id' => self::string($payment->id),
            'status' => self::string($payment->status),
            'reference' => $this->reference($payment->metadata),
        ];
    }

    private function reference(mixed $metadata): string
    {
        if (is_object($metadata) && isset($metadata->reference) && is_string($metadata->reference)) {
            return $metadata->reference;
        }

        if (is_array($metadata) && isset($metadata['reference']) && is_string($metadata['reference'])) {
            return $metadata['reference'];
        }

        return '';
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
