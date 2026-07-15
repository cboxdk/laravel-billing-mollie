<?php

declare(strict_types=1);

namespace Cbox\Billing\Mollie\Testing;

use Cbox\Billing\Mollie\Contracts\MollieIntentCreator;
use Cbox\Billing\Mollie\Exceptions\MollieChargeFailed;

/**
 * A scripted intent creator for tests — returns a fixed id/status or throws, for both
 * the charge and the refund path, with no SDK or network.
 */
class FakeMollieIntentCreator implements MollieIntentCreator
{
    /** @var list<string> the idempotency keys the gateway passed on charge, in order */
    public array $idempotencyKeys = [];

    /** @var list<string> the idempotency keys the gateway passed on refund, in order */
    public array $refundIdempotencyKeys = [];

    public function __construct(
        private string $status = 'paid',
        private string $id = 'tr_fake',
        private bool $fail = false,
        private string $refundStatus = 'refunded',
        private string $refundId = 're_fake',
    ) {}

    public function create(string $amount, string $currency, string $reference, string $idempotencyKey): array
    {
        $this->idempotencyKeys[] = $idempotencyKey;

        if ($this->fail) {
            throw new MollieChargeFailed('payment_refused');
        }

        return ['id' => $this->id, 'status' => $this->status];
    }

    public function refund(string $amount, string $currency, string $paymentId, string $idempotencyKey): array
    {
        $this->refundIdempotencyKeys[] = $idempotencyKey;

        if ($this->fail) {
            throw new MollieChargeFailed('refund_failed');
        }

        return ['id' => $this->refundId, 'status' => $this->refundStatus];
    }
}
