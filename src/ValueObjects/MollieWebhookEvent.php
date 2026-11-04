<?php

declare(strict_types=1);

namespace Cbox\Billing\Mollie\ValueObjects;

use Cbox\Billing\Mollie\MollieApiWebhookVerifier;
use Cbox\Billing\Payment\Enums\PaymentStatus;
use Cbox\Billing\Payment\Enums\WebhookEventType;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;
use Cbox\Billing\Payment\ValueObjects\WebhookEvent;

/**
 * A verified Mollie webhook: the engine's {@see WebhookEvent}, plus the full {@see MolliePayment}
 * the verifier fetched. The engine reads the narrow fields (a settlement settles the reference
 * once); a host that wants the rest (the refunded total, a chargeback, the mandate a payment was
 * charged against, the failure reason) reads `$payment`.
 *
 * A settlement settles under the Mollie payment id (`tr_…`), the reference a refund takes. The
 * event id is the payment's state key ({@see MolliePayment::stateKey()}), so a later webhook for a
 * refund or a chargeback on a payment that is still `paid` is a new event, not a redelivery.
 *
 * Built only by {@see MollieApiWebhookVerifier}, after the delivery was proved.
 */
readonly class MollieWebhookEvent extends WebhookEvent
{
    public function __construct(WebhookEventType $type, public MolliePayment $payment)
    {
        parent::__construct($payment->stateKey(), $type, $payment->reference, $payment->amount);
    }

    public function toPaymentResult(): PaymentResult
    {
        return match ($this->type) {
            WebhookEventType::PaymentSettled => PaymentResult::succeeded($this->payment->id),
            WebhookEventType::PaymentFailed => new PaymentResult(PaymentStatus::Failed, $this->payment->id, $this->payment->failureReason ?? 'payment_'.$this->payment->status),
            default => parent::toPaymentResult(),
        };
    }
}
