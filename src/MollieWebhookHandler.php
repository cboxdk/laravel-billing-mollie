<?php

declare(strict_types=1);

namespace Cbox\Billing\Mollie;

use Cbox\Billing\Payment\Contracts\WebhookIngest;
use Cbox\Billing\Payment\Contracts\WebhookVerifier;
use Cbox\Billing\Payment\Exceptions\WebhookVerificationFailed;
use Cbox\Billing\Payment\ValueObjects\IngestOutcome;
use Cbox\Billing\Payment\ValueObjects\WebhookPayload;

/**
 * The adapter's inbound-webhook entry point: prove the Mollie delivery authentic (and
 * fetch the payment it points at), then hand the normalised event to the engine's
 * exactly-once ingest. It composes two shared seams and owns no idempotency logic of its
 * own:
 *
 *  1. {@see WebhookVerifier} — the Mollie-backed verifier proves the signature
 *     (deny-by-default: an unverified payload throws {@see WebhookVerificationFailed}
 *     and never reaches the ingest), fetches the payment's authoritative state (a Mollie
 *     webhook carries only the payment id), and normalises the event.
 *  2. {@see WebhookIngest} — the engine's exactly-once ingest applies the paid effect to
 *     the invoice at most once per reference, collapsing gateway re-deliveries and
 *     crash-retries; the returned {@see IngestOutcome} tells the host what happened.
 *
 * A fetch failure propagates (as a Mollie exception) so the host can return a 5xx and
 * let Mollie retry the delivery, rather than being swallowed as a verification failure.
 */
readonly class MollieWebhookHandler
{
    public function __construct(
        private WebhookVerifier $verifier,
        private WebhookIngest $ingest,
    ) {}

    /**
     * @throws WebhookVerificationFailed when the payload is not provably authentic.
     */
    public function handle(WebhookPayload $payload): IngestOutcome
    {
        return $this->ingest->ingest($this->verifier->verify($payload));
    }
}
