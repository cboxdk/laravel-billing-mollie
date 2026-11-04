<?php

declare(strict_types=1);

namespace Cbox\Billing\Mollie\ValueObjects;

use Cbox\Billing\Money\Money;

/**
 * A Mollie payment (`tr_…`) as the API returns it, read through the authenticated fetch a
 * webhook triggers. Mollie's classic webhook carries only the payment id, so this snapshot is
 * the whole truth about the delivery: its status, our reference (from metadata), the amount, the
 * customer and mandate it was charged against, the method, the failure reason when it failed,
 * the running totals refunded and charged back, and the chargebacks themselves.
 *
 * `$failureReason` is the most specific reason Mollie gives: a card's `details.failureReason`
 * (`insufficient_funds`, `authentication_failed`, …) or a direct debit's
 * `details.bankReasonCode` (`AM04`, `MD06`, …). Null when the payment did not fail or Mollie
 * gave no reason.
 */
final readonly class MolliePayment
{
    /**
     * @param  list<MollieChargeback>  $chargebacks
     */
    public function __construct(
        public string $id,
        public string $status,
        public string $reference,
        public Money $amount,
        public ?string $customerId = null,
        public ?string $mandateId = null,
        public ?string $method = null,
        public ?string $sequenceType = null,
        public ?string $failureReason = null,
        public ?Money $amountRefunded = null,
        public ?Money $amountChargedBack = null,
        public array $chargebacks = [],
    ) {}

    /** The refunded total in minor units, or zero when Mollie reports none. */
    public function refundedMinor(): int
    {
        return $this->amountRefunded?->minor() ?? 0;
    }

    /** The charged-back total in minor units, or zero when Mollie reports none. */
    public function chargedBackMinor(): int
    {
        return $this->amountChargedBack?->minor() ?? 0;
    }

    /** How many chargebacks stand, and how many were reversed: what moves when one arrives or reverses. */
    public function chargebackState(): string
    {
        $reversed = count(array_filter($this->chargebacks, static fn (MollieChargeback $c): bool => $c->isReversed()));

        return count($this->chargebacks).'.'.$reversed;
    }

    /**
     * A key that changes whenever Mollie would call the webhook for this payment: a new status, a
     * refund that changes the refunded total, a chargeback that arrives or is reversed. Mollie
     * sends no event id, so this is the first-sight dedup key: a redelivery of the same state
     * collapses, a real change is seen once.
     */
    public function stateKey(): string
    {
        return sprintf('%s:%s:r%d:c%d:%s', $this->id, $this->status, $this->refundedMinor(), $this->chargedBackMinor(), $this->chargebackState());
    }

    /** The newest chargeback, by arrival, or null when there is none. */
    public function latestChargeback(): ?MollieChargeback
    {
        $latest = null;

        foreach ($this->chargebacks as $chargeback) {
            if ($latest === null || ($chargeback->createdAt?->getTimestamp() ?? 0) >= ($latest->createdAt?->getTimestamp() ?? 0)) {
                $latest = $chargeback;
            }
        }

        return $latest;
    }
}
