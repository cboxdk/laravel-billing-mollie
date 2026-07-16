<?php

declare(strict_types=1);

namespace Cbox\Billing\Mollie;

use Cbox\Billing\Payment\Enums\PaymentIntentStatus;
use Cbox\Billing\Payment\Enums\PaymentStatus;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;

/**
 * Maps a Mollie payment status to a billing {@see PaymentResult}. Shared by the
 * inline charge path and the webhook path so both interpret Mollie's statuses
 * identically — the single source of truth for the mapping.
 */
readonly class MollieStatusMapper
{
    public function map(string $status, string $gatewayReference): PaymentResult
    {
        return match ($status) {
            'paid' => PaymentResult::succeeded($gatewayReference),
            'open', 'pending' => PaymentResult::pending($gatewayReference),
            'authorized' => new PaymentResult(PaymentStatus::RequiresAction, $gatewayReference),
            default => PaymentResult::failed("Unexpected Mollie status: {$status}"),
        };
    }

    /**
     * Maps a Mollie refund status to a {@see PaymentResult}. Refunds carry their own
     * status vocabulary: a `refunded` refund has settled, `queued`/`pending`/`processing`
     * is still out of band, and `failed`/`canceled` is a failure.
     */
    public function mapRefund(string $status, string $gatewayReference): PaymentResult
    {
        return match ($status) {
            'refunded' => PaymentResult::succeeded($gatewayReference),
            'queued', 'pending', 'processing' => PaymentResult::pending($gatewayReference),
            default => PaymentResult::failed("Unexpected Mollie refund status: {$status}"),
        };
    }

    /**
     * Maps a Mollie payment status onto the engine's gateway-agnostic
     * {@see PaymentIntentStatus} a product's frontend drives the checkout from. Mollie is
     * redirect / authorization based: a freshly created `open` payment and an `authorized`
     * one both need the customer to act on the hosted checkout (Strong Customer
     * Authentication happens there), so both map to `RequiresAction`; `pending` is
     * accepted-and-settling out of band (`Processing`); a terminal `canceled`/`expired`/
     * `failed` is `Canceled`. Consistent with the webhook mapping so the two agree. Any
     * status we do not recognise resolves conservatively to `RequiresPaymentMethod` — it
     * never over-claims a settlement the webhook has not confirmed.
     */
    public function mapIntentStatus(string $status): PaymentIntentStatus
    {
        return match ($status) {
            'paid' => PaymentIntentStatus::Succeeded,
            'open', 'authorized' => PaymentIntentStatus::RequiresAction,
            'pending' => PaymentIntentStatus::Processing,
            'canceled', 'expired', 'failed' => PaymentIntentStatus::Canceled,
            default => PaymentIntentStatus::RequiresPaymentMethod,
        };
    }
}
