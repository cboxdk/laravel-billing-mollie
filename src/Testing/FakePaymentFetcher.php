<?php

declare(strict_types=1);

namespace Cbox\Billing\Mollie\Testing;

use Cbox\Billing\Mollie\Contracts\PaymentFetcher;
use Cbox\Billing\Mollie\Exceptions\MolliePaymentNotFound;
use Cbox\Billing\Mollie\ValueObjects\MollieChargeback;
use Cbox\Billing\Mollie\ValueObjects\MolliePayment;
use Cbox\Billing\Money\Money;

/**
 * A scripted {@see PaymentFetcher} for tests — returns a payment with a fixed status, reference
 * and amount for the requested id (plus any refund or chargeback the test sets), with no SDK or
 * network. An id listed in `$unknown` is not found, as Mollie answers for a foreign payment.
 */
class FakePaymentFetcher implements PaymentFetcher
{
    /** @var list<string> the payment ids fetched, in order */
    public array $fetched = [];

    /**
     * @param  list<MollieChargeback>  $chargebacks
     * @param  list<string>  $unknown
     */
    public function __construct(
        private string $status = 'paid',
        private string $reference = 'DK-000001',
        private ?Money $amount = null,
        private ?Money $refunded = null,
        private array $chargebacks = [],
        private ?string $failureReason = null,
        private array $unknown = [],
    ) {}

    public function fetch(string $paymentId): MolliePayment
    {
        $this->fetched[] = $paymentId;

        if (in_array($paymentId, $this->unknown, true)) {
            throw new MolliePaymentNotFound('The object referenced by your API request does not exist.', 404);
        }

        $chargedBack = null;

        foreach ($this->chargebacks as $chargeback) {
            $chargedBack = $chargedBack === null ? $chargeback->amount : $chargedBack->plus($chargeback->amount);
        }

        return new MolliePayment(
            id: $paymentId,
            status: $this->status,
            reference: $this->reference,
            amount: $this->amount ?? Money::ofMinor(12500, 'EUR'),
            customerId: 'cst_fake',
            mandateId: 'mdt_fake',
            method: 'creditcard',
            sequenceType: 'recurring',
            failureReason: $this->failureReason,
            amountRefunded: $this->refunded,
            amountChargedBack: $chargedBack,
            chargebacks: $this->chargebacks,
        );
    }
}
