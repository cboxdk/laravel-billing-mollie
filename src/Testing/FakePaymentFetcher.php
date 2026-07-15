<?php

declare(strict_types=1);

namespace Cbox\Billing\Mollie\Testing;

use Cbox\Billing\Mollie\Contracts\PaymentFetcher;

/**
 * A scripted {@see PaymentFetcher} for tests — returns a fixed status and reference
 * for the requested payment id, with no SDK or network.
 */
class FakePaymentFetcher implements PaymentFetcher
{
    public function __construct(
        private string $status = 'paid',
        private string $reference = 'DK-000001',
    ) {}

    public function fetch(string $paymentId): array
    {
        return [
            'id' => $paymentId,
            'status' => $this->status,
            'reference' => $this->reference,
        ];
    }
}
