<?php

declare(strict_types=1);

namespace Cbox\Billing\Mollie\Testing;

use Cbox\Billing\Mollie\Contracts\SettledPaymentStore;

/**
 * In-memory {@see SettledPaymentStore} for tests — records settled references in an
 * array, no database. Shared between a gateway and a webhook handler in a test, it
 * exercises the cross-path no-op backstop exactly as the durable store would.
 */
class FakeSettledPaymentStore implements SettledPaymentStore
{
    /** @var array<string, true> */
    public array $settled = [];

    public function isSettled(string $reference): bool
    {
        return isset($this->settled[$reference]);
    }

    public function markSettled(string $reference): void
    {
        $this->settled[$reference] = true;
    }
}
