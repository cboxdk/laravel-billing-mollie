<?php

declare(strict_types=1);

namespace Cbox\Billing\Mollie;

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
}
