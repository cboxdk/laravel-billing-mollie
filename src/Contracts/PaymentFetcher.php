<?php

declare(strict_types=1);

namespace Cbox\Billing\Mollie\Contracts;

use Cbox\Billing\Mollie\Exceptions\MollieChargeFailed;
use Mollie\Api\MollieApiClient;

/**
 * The seam over fetching a Mollie payment's authoritative state. A Mollie webhook
 * carries only the payment id (never the status), so the handler must fetch the
 * payment to learn its current status and our reference. The real implementation
 * wraps {@see MollieApiClient}; a fake backs the tests.
 */
interface PaymentFetcher
{
    /**
     * @return array{id: string, status: string, reference: string}
     *
     * @throws MollieChargeFailed
     */
    public function fetch(string $paymentId): array;
}
