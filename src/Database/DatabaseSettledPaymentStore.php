<?php

declare(strict_types=1);

namespace Cbox\Billing\Mollie\Database;

use Cbox\Billing\Payment\Contracts\SettledPaymentStore;
use Illuminate\Database\ConnectionInterface;

/**
 * Durable {@see SettledPaymentStore} for MySQL/Postgres (and sqlite in tests). A
 * unique index on `reference` plus `insertOrIgnore` makes claiming a reference settled
 * idempotent across the inline charge path and the webhook ingest — the first writer
 * wins and any later re-settle is a no-op. Bound over the engine's shared contract so
 * the exactly-once ingest's settle-once guard is durable across processes and retries.
 */
readonly class DatabaseSettledPaymentStore implements SettledPaymentStore
{
    private const TABLE = 'billing_mollie_settled_payments';

    public function __construct(private ConnectionInterface $db) {}

    public function settle(string $reference): bool
    {
        return $this->db->table(self::TABLE)->insertOrIgnore([
            'reference' => $reference,
            'settled_at' => (int) (microtime(true) * 1000),
        ]) === 1;
    }

    public function isSettled(string $reference): bool
    {
        return $this->db->table(self::TABLE)->where('reference', $reference)->exists();
    }
}
