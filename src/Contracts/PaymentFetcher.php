<?php

declare(strict_types=1);

namespace Cbox\Billing\Mollie\Contracts;

use Cbox\Billing\Mollie\Exceptions\MollieChargeFailed;
use Cbox\Billing\Mollie\Exceptions\MolliePaymentNotFound;
use Cbox\Billing\Mollie\ValueObjects\MolliePayment;
use Mollie\Api\MollieApiClient;

/**
 * The seam over fetching a Mollie payment's authoritative state. A classic Mollie webhook
 * carries only the payment id (never the status or amount), so the verifier fetches the payment
 * with the account's own API key to normalise it onto the engine's `WebhookEvent`. That fetch is
 * also what proves the delivery: a payment id the account does not own is not found. The real
 * implementation wraps {@see MollieApiClient}; a fake backs the tests.
 */
interface PaymentFetcher
{
    /**
     * @throws MolliePaymentNotFound when the account has no such payment (an unknown or foreign id)
     * @throws MollieChargeFailed when Mollie could not be asked (network, 5xx, a revoked key)
     */
    public function fetch(string $paymentId): MolliePayment;
}
