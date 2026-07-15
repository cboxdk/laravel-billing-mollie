<?php

declare(strict_types=1);

namespace Cbox\Billing\Mollie\Contracts;

use Cbox\Billing\Mollie\Exceptions\MollieChargeFailed;
use Cbox\Billing\Money\Money;
use Mollie\Api\MollieApiClient;

/**
 * The seam over fetching a Mollie payment's authoritative state. A Mollie webhook
 * carries only the payment id (never the status or amount), so the verifier must fetch
 * the payment to normalise it onto the engine's `WebhookEvent`: its current status, our
 * reference (from metadata), and the settled amount. The real implementation wraps
 * {@see MollieApiClient}; a fake backs the tests.
 */
interface PaymentFetcher
{
    /**
     * @return array{id: string, status: string, reference: string, amount: Money}
     *
     * @throws MollieChargeFailed
     */
    public function fetch(string $paymentId): array;
}
