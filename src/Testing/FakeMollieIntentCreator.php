<?php

declare(strict_types=1);

namespace Cbox\Billing\Mollie\Testing;

use Cbox\Billing\Mollie\Contracts\MollieIntentCreator;
use Cbox\Billing\Mollie\Exceptions\MollieChargeFailed;

/**
 * A scripted intent creator for tests — returns a fixed id/status or throws, with
 * no SDK or network.
 */
class FakeMollieIntentCreator implements MollieIntentCreator
{
    /** @var list<string> the idempotency keys the gateway passed, in order */
    public array $idempotencyKeys = [];

    public function __construct(
        private string $status = 'paid',
        private string $id = 'tr_fake',
        private bool $fail = false,
    ) {}

    public function create(string $amount, string $currency, string $reference, string $idempotencyKey): array
    {
        $this->idempotencyKeys[] = $idempotencyKey;

        if ($this->fail) {
            throw new MollieChargeFailed('payment_refused');
        }

        return ['id' => $this->id, 'status' => $this->status];
    }
}
