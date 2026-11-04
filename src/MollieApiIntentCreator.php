<?php

declare(strict_types=1);

namespace Cbox\Billing\Mollie;

use Cbox\Billing\Mollie\Contracts\MollieIntentCreator;
use Cbox\Billing\Mollie\Exceptions\MollieChargeFailed;
use Cbox\Billing\Mollie\Support\PaymentReader;
use Closure;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\Http\ResponseStatusCode;
use Mollie\Api\MollieApiClient;
use Throwable;

/**
 * The real Mollie-SDK-backed intent creator. Thin: it makes the API call and
 * normalises the result; decision logic lives in the gateway. Mollie is
 * redirect-based, so a created payment starts `open` (pending) until the customer
 * completes it.
 *
 * Every payment it creates carries the `webhookUrl` Mollie calls when the payment changes
 * (paid, failed, refunded, charged back): without one, Mollie never reports back. The redirect
 * URL, the webhook URL and the setup amount may be closures, read per call, so a host can send
 * the customer back to the page they came from and point the webhook at the environment that
 * made the payment.
 *
 * An SDK failure surfaces as {@see MollieChargeFailed} carrying the SDK exception as its
 * previous, so a host can tell a timeout from a refusal.
 */
readonly class MollieApiIntentCreator implements MollieIntentCreator
{
    private const SETUP_DESCRIPTION = 'Save payment method for future billing';

    /** The brand a SEPA Direct Debit mandate reports, the same word the engine's other adapters use. */
    public const SEPA_BRAND = 'sepa_debit';

    /**
     * @param  string|Closure(): string  $redirectUrl  where Mollie sends the customer after the checkout
     * @param  string|Closure(): string  $setupAmount  the first payment that sets up a mandate (`0.00` saves a card at no charge)
     * @param  string|Closure(): string  $webhookUrl  where Mollie reports the payment's changes; empty sends none
     */
    public function __construct(
        private MollieApiClient $client,
        private string|Closure $redirectUrl,
        private string|Closure $setupAmount = '0.00',
        private string $setupCurrency = 'EUR',
        private string|Closure $webhookUrl = '',
    ) {}

    public function create(string $amount, string $currency, string $reference, string $idempotencyKey): array
    {
        try {
            $this->client->setIdempotencyKey($idempotencyKey);

            $payment = $this->client->payments->create($this->withUrls([
                'amount' => ['currency' => $currency, 'value' => $amount],
                'description' => $reference,
                'metadata' => ['reference' => $reference],
            ]));
        } catch (Throwable $e) {
            throw new MollieChargeFailed($e->getMessage(), (int) $e->getCode(), $e);
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
            throw new MollieChargeFailed($e->getMessage(), (int) $e->getCode(), $e);
        }

        return [
            'id' => (string) $refund->id,
            'status' => (string) $refund->status,
        ];
    }

    public function createIntent(string $amount, string $currency, string $account, string $reference, string $idempotencyKey, ?string $mandateId): array
    {
        $recurring = $mandateId !== null && $mandateId !== '';
        $params = [
            'amount' => ['currency' => $currency, 'value' => $amount],
            'description' => $reference,
            'metadata' => ['reference' => $reference],
            'customerId' => $account,
        ];

        if ($recurring) {
            // Charge the already-saved mandate off-session (no redirect / checkout URL).
            $params['sequenceType'] = 'recurring';
            $params['mandateId'] = $mandateId;
        }

        try {
            $this->client->setIdempotencyKey($idempotencyKey);
            $payment = $this->client->payments->create($this->withUrls($params, redirect: ! $recurring));
        } catch (Throwable $e) {
            throw new MollieChargeFailed($e->getMessage(), (int) $e->getCode(), $e);
        }

        return [
            'id' => (string) $payment->id,
            'status' => (string) $payment->status,
            'checkoutUrl' => $payment->getCheckoutUrl(),
            'failureReason' => PaymentReader::read($payment)->failureReason,
        ];
    }

    public function createSetup(string $account, string $idempotencyKey): array
    {
        try {
            $this->client->setIdempotencyKey($idempotencyKey);
            $payment = $this->client->payments->create($this->withUrls([
                'amount' => ['currency' => $this->setupCurrency, 'value' => self::resolve($this->setupAmount)],
                'description' => self::SETUP_DESCRIPTION,
                'customerId' => $account,
                // `first` establishes a reusable mandate once the customer completes it.
                'sequenceType' => 'first',
            ]));
        } catch (Throwable $e) {
            throw new MollieChargeFailed($e->getMessage(), (int) $e->getCode(), $e);
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
            throw new MollieChargeFailed($e->getMessage(), (int) $e->getCode(), $e);
        }

        $defaultId = self::firstValidMandateId($mandates);

        $mapped = [];

        foreach ($mandates as $mandate) {
            // An invalid mandate (the customer's bank refused it, or its first payment never
            // completed) can never be charged, so it is not a saved method.
            if (self::prop($mandate, 'status') === 'invalid') {
                continue;
            }

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
            throw new MollieChargeFailed($e->getMessage(), (int) $e->getCode(), $e);
        }

        return self::mapMandate($mandate, false);
    }

    public function setDefaultMethod(string $account, string $mandateId): void
    {
        // Mollie has no default-mandate endpoint: a recurring charge auto-selects the first
        // valid mandate, so there is nothing to persist here. The gateway still routes
        // through the seam uniformly, and the fake simulates a reassignable vault for tests.
    }

    public function createCustomer(string $account, ?string $email, ?string $name): string
    {
        try {
            $customer = $this->client->customers->create(array_filter([
                'name' => $name,
                'email' => $email,
                'metadata' => ['account' => $account],
            ], static fn (mixed $value): bool => $value !== null));
        } catch (Throwable $e) {
            // A customer that was never created must not be returned — surface the failure.
            throw new MollieChargeFailed($e->getMessage(), (int) $e->getCode(), $e);
        }

        return (string) $customer->id;
    }

    public function revokeMandate(string $customerId, string $mandateId): void
    {
        try {
            $this->client->mandates->revokeForId($customerId, $mandateId);
        } catch (ApiException $e) {
            // Idempotent teardown: a mandate Mollie reports as already revoked (410 Gone) or
            // unknown (404 Not Found) is the desired end state, so a retry collapses cleanly.
            if (in_array($e->getCode(), [ResponseStatusCode::HTTP_GONE, ResponseStatusCode::HTTP_NOT_FOUND], true)) {
                return;
            }

            throw new MollieChargeFailed($e->getMessage(), (int) $e->getCode(), $e);
        } catch (Throwable $e) {
            throw new MollieChargeFailed($e->getMessage(), (int) $e->getCode(), $e);
        }
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
     * Normalise a Mollie mandate onto the seam's display shape, reading only the non-sensitive
     * fields Mollie exposes, never a full card number or IBAN:
     *
     *  - a card mandate (`creditcard`): the card label as the brand, the last four digits Mollie
     *    gives, and the expiry;
     *  - a SEPA Direct Debit mandate (`directdebit`): the brand `sepa_debit` and the last four
     *    characters of the IBAN, with no expiry.
     *
     * @return array{id: string, brand: string, last4: string, expMonth: ?int, expYear: ?int, isDefault: bool}
     */
    private static function mapMandate(mixed $mandate, bool $isDefault): array
    {
        $details = is_object($mandate) && isset($mandate->details) ? $mandate->details : null;
        $method = self::prop($mandate, 'method');

        if ($method === 'directdebit') {
            return [
                'id' => self::prop($mandate, 'id'),
                'brand' => self::SEPA_BRAND,
                'last4' => self::last4(str_replace(' ', '', self::detail($details, 'consumerAccount'))),
                'expMonth' => null,
                'expYear' => null,
                'isDefault' => $isDefault,
            ];
        }

        [$expMonth, $expYear] = self::expiry(self::detail($details, 'cardExpiryDate'));
        $label = self::detail($details, 'cardLabel');

        return [
            'id' => self::prop($mandate, 'id'),
            'brand' => $label !== '' ? $label : ($method !== '' ? $method : 'card'),
            'last4' => self::last4(self::detail($details, 'cardNumber')),
            'expMonth' => $expMonth,
            'expYear' => $expYear,
            'isDefault' => $isDefault,
        ];
    }

    /**
     * The payment's create parameters with the URLs Mollie needs: the redirect for a payment the
     * customer completes at the checkout, and the webhook for every payment, when one is set.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function withUrls(array $params, bool $redirect = true): array
    {
        $redirectUrl = self::resolve($this->redirectUrl);
        $webhookUrl = self::resolve($this->webhookUrl);

        if ($redirect && $redirectUrl !== '') {
            $params['redirectUrl'] = $redirectUrl;
        }

        if ($webhookUrl !== '') {
            $params['webhookUrl'] = $webhookUrl;
        }

        return $params;
    }

    /** @param  string|Closure(): string  $value */
    private static function resolve(string|Closure $value): string
    {
        return $value instanceof Closure ? $value() : $value;
    }

    /**
     * Read a string property off a Mollie SDK resource (dynamic `public` properties),
     * defensively — an absent or non-string value normalises to an empty string.
     */
    private static function prop(mixed $resource, string $key): string
    {
        return PaymentReader::string($resource, $key);
    }

    /**
     * Read a string field off a mandate's `details` object (card label / number / expiry, or
     * the bank account), defensively.
     */
    private static function detail(mixed $details, string $key): string
    {
        return self::prop($details, $key);
    }

    /**
     * The last four characters of a mandate's masked card number or IBAN (Mollie already
     * exposes only the trailing digits of a card; guard defensively regardless).
     */
    private static function last4(string $number): string
    {
        return $number === '' ? '' : substr($number, -4);
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
