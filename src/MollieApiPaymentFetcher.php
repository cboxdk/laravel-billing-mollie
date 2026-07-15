<?php

declare(strict_types=1);

namespace Cbox\Billing\Mollie;

use Brick\Money\Money as BrickMoney;
use Cbox\Billing\Mollie\Contracts\PaymentFetcher;
use Cbox\Billing\Mollie\Exceptions\MollieChargeFailed;
use Cbox\Billing\Money\Money;
use Mollie\Api\MollieApiClient;
use Throwable;

/**
 * The real payment fetcher: wraps {@see MollieApiClient} to read a payment's current
 * status, our reference (from its metadata), and its amount by id. Thin by design — the
 * verifier owns the normalisation and the ingest owns the idempotency. Verify against
 * the live Mollie API before relying on it in production.
 */
readonly class MollieApiPaymentFetcher implements PaymentFetcher
{
    /**
     * Currency for the placeholder zero amount when a payment carries no parseable
     * amount (only non-settled statuses, whose amount the ingest never reads).
     */
    private const PLACEHOLDER_CURRENCY = 'EUR';

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
            'amount' => $this->amount($payment->amount),
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

    private function amount(mixed $amount): Money
    {
        if (
            is_object($amount)
            && isset($amount->value, $amount->currency)
            && is_string($amount->value)
            && is_string($amount->currency)
            && $amount->currency !== ''
        ) {
            return Money::fromBrick(BrickMoney::of($amount->value, $amount->currency));
        }

        return Money::zero(self::PLACEHOLDER_CURRENCY);
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
