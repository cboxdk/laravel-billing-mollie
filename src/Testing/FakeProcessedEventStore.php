<?php

declare(strict_types=1);

namespace Cbox\Billing\Mollie\Testing;

use Cbox\Billing\Mollie\Contracts\ProcessedEventStore;

/**
 * In-memory {@see ProcessedEventStore} for tests — dedups by event id in an array,
 * no database. Mirrors the durable store's contract: first `remember` of an id is
 * true, subsequent ones are false.
 */
class FakeProcessedEventStore implements ProcessedEventStore
{
    /** @var array<string, true> */
    public array $processed = [];

    public function remember(string $eventId): bool
    {
        if (isset($this->processed[$eventId])) {
            return false;
        }

        $this->processed[$eventId] = true;

        return true;
    }
}
