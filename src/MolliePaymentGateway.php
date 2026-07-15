<?php

declare(strict_types=1);

namespace Cbox\Billing\Mollie;

use Cbox\Billing\Mollie\Contracts\MollieIntentCreator;
use Cbox\Billing\Mollie\Exceptions\MollieChargeFailed;
use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Cbox\Billing\Payment\Contracts\SettledPaymentStore;
use Cbox\Billing\Payment\ValueObjects\PaymentIntent;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;
use Cbox\Billing\Payment\ValueObjects\RefundIntent;

/**
 * A {@see PaymentGateway} backed by Mollie. Creates a Mollie payment for the amount
 * (formatted as a decimal string via the money value object) and maps Mollie's status
 * to a PaymentResult; also refunds a captured amount. An API failure becomes a failed
 * result — never throws.
 *
 * Idempotency properties that live here:
 *
 *  - The payment is created with a scoped external idempotency key
 *    (`reference:amount:currency`), so a crash-and-retry between the API call and
 *    recording the result cannot create a duplicate payment. A refund is scoped by the
 *    intent's own idempotency key so a retry cannot refund twice.
 *  - On a settled charge the reference is claimed in the shared
 *    {@see SettledPaymentStore} — the same settle-once guard the webhook ingest reads,
 *    so a later webhook re-confirming the same payment is a no-op (the backstop).
 *
 * Mollie is redirect-based, so the common create result is `open` (pending); the
 * `paid` settlement usually arrives via the webhook path.
 */
readonly class MolliePaymentGateway implements PaymentGateway
{
    public function __construct(
        private MollieIntentCreator $creator,
        private SettledPaymentStore $settledPayments,
        private MollieStatusMapper $mapper = new MollieStatusMapper,
    ) {}

    public function name(): string
    {
        return 'mollie';
    }

    public function charge(PaymentIntent $intent): PaymentResult
    {
        $amount = (string) $intent->amount->toBrick()->getAmount();

        try {
            $result = $this->creator->create(
                $amount,
                $intent->amount->currency(),
                $intent->reference,
                $this->idempotencyKey($intent),
            );
        } catch (MollieChargeFailed $e) {
            return PaymentResult::failed($e->getMessage());
        }

        $mapped = $this->mapper->map($result['status'], $result['id']);

        if ($mapped->isSettled()) {
            $this->settledPayments->settle($intent->reference);
        }

        return $mapped;
    }

    public function refund(RefundIntent $intent): PaymentResult
    {
        $amount = (string) $intent->amount->toBrick()->getAmount();

        try {
            $result = $this->creator->refund(
                $amount,
                $intent->amount->currency(),
                $intent->originalGatewayReference ?? '',
                $intent->idempotencyKey,
            );
        } catch (MollieChargeFailed $e) {
            return PaymentResult::failed($e->getMessage());
        }

        return $this->mapper->mapRefund($result['status'], $result['id']);
    }

    /**
     * Scoped external idempotency key: the reference already encodes the billing
     * period for recurring charges, and the amount pins it per one-off charge, so a
     * safe retry resolves to the same Mollie payment rather than a second one.
     */
    private function idempotencyKey(PaymentIntent $intent): string
    {
        return sprintf(
            'cbx-%s-%d-%s',
            $intent->reference,
            $intent->amount->minor(),
            $intent->amount->currency(),
        );
    }
}
