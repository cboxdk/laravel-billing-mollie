<?php

declare(strict_types=1);

namespace Cbox\Billing\Mollie\Contracts;

/**
 * Records which payment references have already been settled, keyed on the
 * invoice/payment reference (not the webhook event). This is the per-reference
 * idempotency guard and the no-op backstop in one: the inline `charge()` path and
 * the webhook path both write here, so whichever settles a reference first wins and
 * the other — a different event about the same object, or a redelivery arriving after
 * an inline settle — becomes a no-op.
 */
interface SettledPaymentStore
{
    public function isSettled(string $reference): bool;

    public function markSettled(string $reference): void;
}
