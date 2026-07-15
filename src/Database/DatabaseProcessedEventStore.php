<?php

declare(strict_types=1);

namespace Cbox\Billing\Mollie\Database;

use Cbox\Billing\Mollie\Contracts\ProcessedEventStore;
use Illuminate\Database\ConnectionInterface;

/**
 * Durable {@see ProcessedEventStore} for MySQL/Postgres (and sqlite in tests). Dedup
 * is enforced by a unique index on `event_id` via `insertOrIgnore`: the database
 * decides the race, so two concurrent deliveries of the same event cannot both be
 * told they are new. Append-only — rows are never updated or deleted.
 */
readonly class DatabaseProcessedEventStore implements ProcessedEventStore
{
    private const TABLE = 'billing_mollie_processed_events';

    public function __construct(private ConnectionInterface $db) {}

    public function remember(string $eventId): bool
    {
        return $this->db->table(self::TABLE)->insertOrIgnore([
            'event_id' => $eventId,
            'processed_at' => (int) (microtime(true) * 1000),
        ]) === 1;
    }
}
