<?php

declare(strict_types=1);

namespace Cbox\Billing\Mollie\Testing;

use Cbox\Billing\Mollie\Contracts\PaymentFetcher;
use Cbox\Billing\Money\Money;

/**
 * A scripted {@see PaymentFetcher} for tests — returns a fixed status, reference, and
 * amount for the requested payment id, with no SDK or network.
 */
class FakePaymentFetcher implements PaymentFetcher
{
    public function __construct(
        private string $status = 'paid',
        private string $reference = 'DK-000001',
        private ?Money $amount = null,
    ) {}

    public function fetch(string $paymentId): array
    {
        return [
            'id' => $paymentId,
            'status' => $this->status,
            'reference' => $this->reference,
            'amount' => $this->amount ?? Money::ofMinor(12500, 'EUR'),
        ];
    }
}
