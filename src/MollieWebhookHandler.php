<?php

declare(strict_types=1);

namespace Cbox\Billing\Mollie;

use Cbox\Billing\Mollie\Contracts\PaymentFetcher;
use Cbox\Billing\Mollie\Contracts\ProcessedEventStore;
use Cbox\Billing\Mollie\Contracts\SettledPaymentStore;
use Cbox\Billing\Mollie\Contracts\WebhookVerifier;
use Cbox\Billing\Mollie\Exceptions\WebhookVerificationFailed;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;

/**
 * Turns a raw Mollie webhook delivery into an idempotent {@see PaymentResult}. A
 * Mollie webhook carries only the payment id, so the handler fetches the payment's
 * authoritative status, then applies three idempotency layers over it:
 *
 *  1. Signature verification (deny-by-default) — an unverified payload never touches
 *     state; it throws {@see WebhookVerificationFailed}.
 *  2. Event-id dedup — Mollie has no native event id, so the stable identity is the
 *     payment id plus its current status; a redelivery of the same state is a no-op.
 *     A genuine state change (e.g. open→paid) is a distinct event and is processed.
 *  3. Per-reference settle-once + backstop — a `paid` event settles a reference only
 *     if it is not already settled; if the inline `charge()` path (or an earlier
 *     event) already settled it, re-confirmation is a no-op.
 *
 * Returns the mapped result for the host to apply, or null when the delivery is a
 * duplicate / not actionable. A fetch failure propagates so the host can return a 5xx
 * and let Mollie retry the delivery.
 */
readonly class MollieWebhookHandler
{
    public function __construct(
        private WebhookVerifier $verifier,
        private PaymentFetcher $fetcher,
        private ProcessedEventStore $processedEvents,
        private SettledPaymentStore $settledPayments,
        private MollieStatusMapper $mapper = new MollieStatusMapper,
    ) {}

    /**
     * @throws WebhookVerificationFailed
     */
    public function handle(string $payload, string $signature): ?PaymentResult
    {
        $paymentId = $this->verifier->verify($payload, $signature);
        $payment = $this->fetcher->fetch($paymentId);

        // Mollie carries no event id; its stable identity is payment id + status.
        $eventId = $payment['id'].':'.$payment['status'];

        if (! $this->processedEvents->remember($eventId)) {
            return null;
        }

        $result = $this->mapper->map($payment['status'], $payment['id']);

        if (! $result->isSettled() || $payment['reference'] === '') {
            return $result;
        }

        // Per-reference settle-once + no-op backstop: only the first path to settle
        // this reference applies the effect.
        if ($this->settledPayments->isSettled($payment['reference'])) {
            return null;
        }

        $this->settledPayments->markSettled($payment['reference']);

        return $result;
    }
}
