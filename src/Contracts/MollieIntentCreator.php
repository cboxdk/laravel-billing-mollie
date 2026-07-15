<?php

declare(strict_types=1);

namespace Cbox\Billing\Mollie\Contracts;

use Cbox\Billing\Mollie\Exceptions\MollieChargeFailed;

/**
 * The seam over the Mollie SDK: create a payment for a charge, and refund a
 * previously-captured amount. Isolating the SDK makes the gateway's mapping logic
 * unit-testable without the network. The amount is a decimal string (Mollie's format),
 * e.g. "125.00".
 *
 * `$idempotencyKey` is a caller-scoped external idempotency key passed straight to
 * Mollie (the SDK's `Idempotency-Key` header): if the process crashes between the API
 * call and recording its result, a retry with the same key returns the original
 * payment (or refund) instead of creating a second one, so both steps are resume-safe.
 */
interface MollieIntentCreator
{
    /**
     * @return array{id: string, status: string}
     *
     * @throws MollieChargeFailed
     */
    public function create(string $amount, string $currency, string $reference, string $idempotencyKey): array;

    /**
     * Refund `$amount` (a decimal string) against the original Mollie payment (`tr_…`),
     * scoped by the idempotency key so a retry or a re-delivered webhook collapses to one
     * refund.
     *
     * @return array{id: string, status: string}
     *
     * @throws MollieChargeFailed
     */
    public function refund(string $amount, string $currency, string $paymentId, string $idempotencyKey): array;
}
