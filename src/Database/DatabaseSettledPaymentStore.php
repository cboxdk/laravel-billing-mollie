<?php

declare(strict_types=1);

namespace Cbox\Billing\Mollie\Database;

use Cbox\Billing\Mollie\Contracts\SettledPaymentStore;
use Illuminate\Database\ConnectionInterface;

/**
 * Durable {@see SettledPaymentStore} for MySQL/Postgres (and sqlite in tests). A
 * unique index on `reference` plus `insertOrIgnore` makes marking a reference settled
 * idempotent across the inline and webhook paths — the first writer wins and any
 * later re-settle is silently ignored.
 */
readonly class DatabaseSettledPaymentStore implements SettledPaymentStore
{
    private const TABLE = 'billing_mollie_settled_payments';

    public function __construct(private ConnectionInterface $db) {}

    public function isSettled(string $reference): bool
    {
        return $this->db->table(self::TABLE)->where('reference', $reference)->exists();
    }

    public function markSettled(string $reference): void
    {
        $this->db->table(self::TABLE)->insertOrIgnore([
            'reference' => $reference,
            'settled_at' => (int) (microtime(true) * 1000),
        ]);
    }
}
