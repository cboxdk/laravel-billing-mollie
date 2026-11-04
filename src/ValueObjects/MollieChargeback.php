<?php

declare(strict_types=1);

namespace Cbox\Billing\Mollie\ValueObjects;

use Cbox\Billing\Money\Money;
use DateTimeImmutable;

/**
 * One chargeback on a Mollie payment (`chb_…`), as the payment's embedded chargebacks list
 * reports it: the amount the bank pulled back, the bank's reason code and description, when it
 * arrived, and when Mollie reversed it (the merchant won it back) or null while it stands.
 *
 * Mollie has no API to contest a chargeback: a card chargeback is defended in the Mollie
 * Dashboard, and a SEPA Direct Debit reversal cannot be contested at all.
 */
final readonly class MollieChargeback
{
    public function __construct(
        public string $id,
        public Money $amount,
        public string $reasonCode,
        public string $reasonDescription,
        public ?DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $reversedAt,
    ) {}

    public function isReversed(): bool
    {
        return $this->reversedAt !== null;
    }
}
