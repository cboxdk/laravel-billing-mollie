<?php

declare(strict_types=1);

namespace Cbox\Billing\Mollie;

use Cbox\Billing\Mollie\Contracts\PaymentFetcher;
use Cbox\Billing\Mollie\Exceptions\MollieChargeFailed;
use Cbox\Billing\Mollie\Exceptions\MolliePaymentNotFound;
use Cbox\Billing\Mollie\Support\PaymentReader;
use Cbox\Billing\Mollie\ValueObjects\MolliePayment;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\Http\ResponseStatusCode;
use Mollie\Api\MollieApiClient;
use Throwable;

/**
 * The real payment fetcher: `GET /v2/payments/{id}?embed=chargebacks` with the account's own API
 * key, read into a {@see MolliePayment}. The chargebacks are embedded so a webhook Mollie sends
 * for a new or reversed chargeback carries it in the same request. Thin by design: the verifier
 * owns the normalisation and the ingest owns the idempotency.
 */
readonly class MollieApiPaymentFetcher implements PaymentFetcher
{
    public function __construct(private MollieApiClient $client) {}

    public function fetch(string $paymentId): MolliePayment
    {
        try {
            $payment = $this->client->payments->get($paymentId, ['embed' => 'chargebacks']);
        } catch (ApiException $e) {
            if ($e->getCode() === ResponseStatusCode::HTTP_NOT_FOUND) {
                throw new MolliePaymentNotFound($e->getMessage(), $e->getCode(), $e);
            }

            throw new MollieChargeFailed($e->getMessage(), $e->getCode(), $e);
        } catch (Throwable $e) {
            throw new MollieChargeFailed($e->getMessage(), previous: $e);
        }

        return PaymentReader::read($payment);
    }
}
