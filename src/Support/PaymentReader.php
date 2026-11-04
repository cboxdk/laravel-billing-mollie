<?php

declare(strict_types=1);

namespace Cbox\Billing\Mollie\Support;

use Brick\Money\Money as BrickMoney;
use Cbox\Billing\Mollie\ValueObjects\MollieChargeback;
use Cbox\Billing\Mollie\ValueObjects\MolliePayment;
use Cbox\Billing\Money\Money;
use DateTimeImmutable;
use Throwable;

/**
 * Reads a Mollie SDK payment resource (dynamic public properties hydrated from the API's JSON)
 * into a {@see MolliePayment}, defensively: an absent or mistyped field reads as null or empty
 * rather than throwing, because the fields Mollie sends differ per method and status.
 *
 * Shared by the webhook fetch and by anything that lists a customer's payments, so both read a
 * payment the same way.
 */
final class PaymentReader
{
    /** Currency for the zero amount used when a payment carries no parseable amount. */
    private const PLACEHOLDER_CURRENCY = 'EUR';

    public static function read(object $payment): MolliePayment
    {
        $details = self::field($payment, 'details');

        return new MolliePayment(
            id: self::string($payment, 'id'),
            status: self::string($payment, 'status'),
            reference: self::reference(self::field($payment, 'metadata')),
            amount: self::money(self::field($payment, 'amount')) ?? Money::zero(self::PLACEHOLDER_CURRENCY),
            customerId: self::nullable(self::string($payment, 'customerId')),
            mandateId: self::nullable(self::string($payment, 'mandateId')),
            method: self::nullable(self::string($payment, 'method')),
            sequenceType: self::nullable(self::string($payment, 'sequenceType')),
            failureReason: self::nullable(self::string($details, 'failureReason'))
                ?? self::nullable(self::string($details, 'bankReasonCode')),
            amountRefunded: self::money(self::field($payment, 'amountRefunded')),
            amountChargedBack: self::money(self::field($payment, 'amountChargedBack')),
            chargebacks: self::chargebacks(self::field(self::field($payment, '_embedded'), 'chargebacks')),
        );
    }

    /**
     * A Mollie amount object (`{"currency": "EUR", "value": "10.00"}`) as money, or null.
     */
    public static function money(mixed $amount): ?Money
    {
        $value = self::string($amount, 'value');
        $currency = self::string($amount, 'currency');

        if ($value === '' || $currency === '') {
            return null;
        }

        try {
            return Money::fromBrick(BrickMoney::of($value, $currency));
        } catch (Throwable) {
            return null;
        }
    }

    /** Our reference, from the metadata we created the payment with (an object, or a JSON string). */
    public static function reference(mixed $metadata): string
    {
        if (is_string($metadata) && $metadata !== '') {
            $metadata = json_decode($metadata, true);
        }

        if (is_object($metadata)) {
            $metadata = get_object_vars($metadata);
        }

        $reference = is_array($metadata) ? ($metadata['reference'] ?? null) : null;

        return is_string($reference) ? $reference : '';
    }

    /**
     * @return list<MollieChargeback>
     */
    private static function chargebacks(mixed $embedded): array
    {
        if (! is_iterable($embedded)) {
            return [];
        }

        $chargebacks = [];

        foreach ($embedded as $chargeback) {
            if (! is_object($chargeback) && ! is_array($chargeback)) {
                continue;
            }

            $amount = self::money(self::field($chargeback, 'amount'));
            $reason = self::field($chargeback, 'reason');

            $chargebacks[] = new MollieChargeback(
                id: self::string($chargeback, 'id'),
                amount: $amount ?? Money::zero(self::PLACEHOLDER_CURRENCY),
                reasonCode: self::string($reason, 'code'),
                reasonDescription: self::string($reason, 'description'),
                createdAt: self::date(self::string($chargeback, 'createdAt')),
                reversedAt: self::date(self::string($chargeback, 'reversedAt')),
            );
        }

        return $chargebacks;
    }

    private static function date(string $value): ?DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }

    /** A property of an SDK resource or a decoded JSON object/array, or null. */
    public static function field(mixed $resource, string $key): mixed
    {
        if (is_array($resource)) {
            return $resource[$key] ?? null;
        }

        if (is_object($resource) && isset($resource->{$key})) {
            return $resource->{$key};
        }

        return null;
    }

    public static function string(mixed $resource, string $key): string
    {
        $value = self::field($resource, $key);

        return is_string($value) ? $value : (is_int($value) ? (string) $value : '');
    }

    private static function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
