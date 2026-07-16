<?php

declare(strict_types=1);

namespace Cbox\Billing\Mollie;

use Cbox\Billing\Mollie\Contracts\MollieIntentCreator;
use Cbox\Billing\Mollie\Exceptions\MollieChargeFailed;
use Mollie\Api\MollieApiClient;
use Throwable;

/**
 * The real Mollie-SDK-backed intent creator. Thin: it makes the API call and
 * normalises the result; decision logic lives in the gateway. Mollie is
 * redirect-based, so a created payment starts `open` (pending) until the customer
 * completes it. Verify against the live Mollie API before production.
 */
readonly class MollieApiIntentCreator implements MollieIntentCreator
{
    private const SETUP_DESCRIPTION = 'Save payment method for future billing';

    public function __construct(
        private MollieApiClient $client,
        private string $redirectUrl,
        private string $setupAmount = '0.00',
        private string $setupCurrency = 'EUR',
    ) {}

    public function create(string $amount, string $currency, string $reference, string $idempotencyKey): array
    {
        try {
            $this->client->setIdempotencyKey($idempotencyKey);

            $payment = $this->client->payments->create([
                'amount' => ['currency' => $currency, 'value' => $amount],
                'description' => $reference,
                'redirectUrl' => $this->redirectUrl,
                'metadata' => ['reference' => $reference],
            ]);
        } catch (Throwable $e) {
            throw new MollieChargeFailed($e->getMessage(), previous: $e);
        }

        return [
            'id' => (string) $payment->id,
            'status' => (string) $payment->status,
        ];
    }

    public function refund(string $amount, string $currency, string $paymentId, string $idempotencyKey): array
    {
        try {
            $this->client->setIdempotencyKey($idempotencyKey);

            $refund = $this->client->paymentRefunds->createForId($paymentId, [
                'amount' => ['currency' => $currency, 'value' => $amount],
            ]);
        } catch (Throwable $e) {
            throw new MollieChargeFailed($e->getMessage(), previous: $e);
        }

        return [
            'id' => (string) $refund->id,
            'status' => (string) $refund->status,
        ];
    }

    public function createIntent(string $amount, string $currency, string $account, string $reference, string $idempotencyKey, ?string $mandateId): array
    {
        $params = [
            'amount' => ['currency' => $currency, 'value' => $amount],
            'description' => $reference,
            'redirectUrl' => $this->redirectUrl,
            'metadata' => ['reference' => $reference],
            'customerId' => $account,
        ];

        if ($mandateId !== null && $mandateId !== '') {
            // Charge the already-saved mandate off-session (no redirect / checkout URL).
            $params['sequenceType'] = 'recurring';
            $params['mandateId'] = $mandateId;
        }

        try {
            $this->client->setIdempotencyKey($idempotencyKey);
            $payment = $this->client->payments->create($params);
        } catch (Throwable $e) {
            throw new MollieChargeFailed($e->getMessage(), previous: $e);
        }

        return [
            'id' => (string) $payment->id,
            'status' => (string) $payment->status,
            'checkoutUrl' => $payment->getCheckoutUrl(),
        ];
    }

    public function createSetup(string $account, string $idempotencyKey): array
    {
        try {
            $this->client->setIdempotencyKey($idempotencyKey);
            $payment = $this->client->payments->create([
                'amount' => ['currency' => $this->setupCurrency, 'value' => $this->setupAmount],
                'description' => self::SETUP_DESCRIPTION,
                'redirectUrl' => $this->redirectUrl,
                'customerId' => $account,
                // `first` establishes a reusable mandate once the customer completes it.
                'sequenceType' => 'first',
            ]);
        } catch (Throwable $e) {
            throw new MollieChargeFailed($e->getMessage(), previous: $e);
        }

        return [
            'id' => (string) $payment->id,
            'status' => (string) $payment->status,
            'checkoutUrl' => $payment->getCheckoutUrl(),
        ];
    }

    public function listMethods(string $account): array
    {
        try {
            $mandates = $this->client->mandates->pageForId($account);
        } catch (Throwable $e) {
            throw new MollieChargeFailed($e->getMessage(), previous: $e);
        }

        $defaultId = self::firstValidMandateId($mandates);

        $mapped = [];

        foreach ($mandates as $mandate) {
            $id = self::prop($mandate, 'id');
            $mapped[] = self::mapMandate($mandate, $id !== '' && $id === $defaultId);
        }

        return $mapped;
    }

    public function attachMethod(string $account, string $mandateId): array
    {
        // The mandate already belongs to the customer (created by the setup payment); the
        // list carries the correct default flag, so prefer it and fall back to a direct
        // fetch only if the mandate is not yet visible in the page.
        foreach ($this->listMethods($account) as $method) {
            if ($method['id'] === $mandateId) {
                return $method;
            }
        }

        try {
            $mandate = $this->client->mandates->getForId($account, $mandateId);
        } catch (Throwable $e) {
            throw new MollieChargeFailed($e->getMessage(), previous: $e);
        }

        return self::mapMandate($mandate, false);
    }

    public function setDefaultMethod(string $account, string $mandateId): void
    {
        // Mollie has no default-mandate endpoint: a recurring charge auto-selects the first
        // valid mandate, so there is nothing to persist here. The gateway still routes
        // through the seam uniformly, and the fake simulates a reassignable vault for tests.
    }

    /**
     * The id of the mandate Mollie would charge — the first with status `valid` — or an
     * empty string when the customer has no usable mandate.
     *
     * @param  iterable<mixed>  $mandates
     */
    private static function firstValidMandateId(iterable $mandates): string
    {
        foreach ($mandates as $mandate) {
            if (self::prop($mandate, 'status') === 'valid') {
                return self::prop($mandate, 'id');
            }
        }

        return '';
    }

    /**
     * Normalise a Mollie mandate onto the seam's display shape, reading only the
     * non-sensitive card fields Mollie exposes (label, last 4, expiry) — never a PAN.
     *
     * @return array{id: string, brand: string, last4: string, expMonth: ?int, expYear: ?int, isDefault: bool}
     */
    private static function mapMandate(mixed $mandate, bool $isDefault): array
    {
        $details = is_object($mandate) && isset($mandate->details) ? $mandate->details : null;

        [$expMonth, $expYear] = self::expiry(self::detail($details, 'cardExpiryDate'));

        return [
            'id' => self::prop($mandate, 'id'),
            'brand' => self::detail($details, 'cardLabel'),
            'last4' => self::last4(self::detail($details, 'cardNumber')),
            'expMonth' => $expMonth,
            'expYear' => $expYear,
            'isDefault' => $isDefault,
        ];
    }

    /**
     * Read a string property off a Mollie SDK resource (dynamic `public` properties),
     * defensively — an absent or non-string value normalises to an empty string.
     */
    private static function prop(mixed $resource, string $key): string
    {
        if (is_object($resource) && isset($resource->{$key}) && is_string($resource->{$key})) {
            return $resource->{$key};
        }

        return '';
    }

    /**
     * Read a string field off a mandate's `details` object (creditcard label / number /
     * expiry), defensively.
     */
    private static function detail(mixed $details, string $key): string
    {
        return self::prop($details, $key);
    }

    /**
     * The last four digits of a mandate's masked card number (Mollie already exposes only
     * the trailing digits; guard defensively regardless).
     */
    private static function last4(string $cardNumber): string
    {
        return $cardNumber === '' ? '' : substr($cardNumber, -4);
    }

    /**
     * Parse a Mollie `cardExpiryDate` (`YYYY-MM-DD`) into month and year, or two nulls when
     * absent/unparseable.
     *
     * @return array{0: ?int, 1: ?int}
     */
    private static function expiry(string $cardExpiryDate): array
    {
        if (! preg_match('/^(\d{4})-(\d{2})/', $cardExpiryDate, $matches)) {
            return [null, null];
        }

        return [(int) $matches[2], (int) $matches[1]];
    }
}
