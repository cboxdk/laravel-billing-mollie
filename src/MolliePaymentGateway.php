<?php

declare(strict_types=1);

namespace Cbox\Billing\Mollie;

use Cbox\Billing\Mollie\Contracts\MollieIntentCreator;
use Cbox\Billing\Mollie\Exceptions\MollieChargeFailed;
use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Cbox\Billing\Payment\Enums\PaymentStatus;
use Cbox\Billing\Payment\ValueObjects\PaymentIntent;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;

/**
 * A {@see PaymentGateway} backed by Mollie. Creates a Mollie payment for the amount
 * (formatted as a decimal string via the money value object) and maps Mollie's
 * status to a PaymentResult. An API failure becomes a failed result — never throws.
 */
readonly class MolliePaymentGateway implements PaymentGateway
{
    public function __construct(private MollieIntentCreator $creator) {}

    public function name(): string
    {
        return 'mollie';
    }

    public function charge(PaymentIntent $intent): PaymentResult
    {
        $amount = (string) $intent->amount->toBrick()->getAmount();

        try {
            $result = $this->creator->create($amount, $intent->amount->currency(), $intent->reference);
        } catch (MollieChargeFailed $e) {
            return PaymentResult::failed($e->getMessage());
        }

        return $this->map($result['status'], $result['id']);
    }

    private function map(string $status, string $gatewayReference): PaymentResult
    {
        return match ($status) {
            'paid' => PaymentResult::succeeded($gatewayReference),
            'open', 'pending' => PaymentResult::pending($gatewayReference),
            'authorized' => new PaymentResult(PaymentStatus::RequiresAction, $gatewayReference),
            default => PaymentResult::failed("Unexpected Mollie status: {$status}"),
        };
    }
}
