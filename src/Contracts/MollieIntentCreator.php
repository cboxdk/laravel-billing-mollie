<?php

declare(strict_types=1);

namespace Cbox\Billing\Mollie\Contracts;

use Cbox\Billing\Mollie\Exceptions\MollieChargeFailed;

/**
 * The seam over the Mollie SDK: create a payment and return its id and status.
 * Isolating the SDK makes the gateway's mapping logic unit-testable without the
 * network. The amount is a decimal string (Mollie's format), e.g. "125.00".
 */
interface MollieIntentCreator
{
    /**
     * @return array{id: string, status: string}
     *
     * @throws MollieChargeFailed
     */
    public function create(string $amount, string $currency, string $reference): array;
}
