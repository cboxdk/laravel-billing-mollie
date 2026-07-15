<?php

declare(strict_types=1);

namespace Cbox\Billing\Mollie\Contracts;

use Cbox\Billing\Mollie\MollieWebhookHandler;

/**
 * Records the webhook event ids that have already been processed, so a retried or
 * replayed delivery is a no-op. Mollie's classic webhook carries no event id, so the
 * stable identity is the payment id plus its current status (see
 * {@see MollieWebhookHandler}). The durable implementation
 * dedups via a unique index (`insertOrIgnore`); an in-memory fake backs the tests.
 */
interface ProcessedEventStore
{
    /**
     * Record the event id and report whether it is newly seen. Returns true on the
     * first delivery (process it) and false if the id was already stored (skip).
     * Must be atomic so two concurrent deliveries cannot both observe true.
     */
    public function remember(string $eventId): bool;
}
