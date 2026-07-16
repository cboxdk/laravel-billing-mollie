<?php

declare(strict_types=1);

namespace Cbox\Billing\Mollie\Testing;

use Cbox\Billing\Mollie\Contracts\MollieIntentCreator;
use Cbox\Billing\Mollie\Exceptions\MollieChargeFailed;

/**
 * A scripted intent creator for tests — returns a fixed id/status or throws, for the
 * charge, refund, on/off-session intent, and stored-mandate paths, with no SDK or network.
 *
 * The stored-method operations behave like a small per-customer mandate vault the suite can
 * assert on: the first mandate attached to a customer becomes its default, and setDefault
 * reassigns the flag — the same observable shape the shared engine fake exposes, so a test
 * reads the real attach/list/default behaviour rather than a canned response.
 */
class FakeMollieIntentCreator implements MollieIntentCreator
{
    /** @var list<string> the idempotency keys the gateway passed on charge, in order */
    public array $idempotencyKeys = [];

    /** @var list<string> the idempotency keys the gateway passed on refund, in order */
    public array $refundIdempotencyKeys = [];

    /** @var list<string> the idempotency keys the gateway passed on intent creation, in order */
    public array $intentIdempotencyKeys = [];

    /** @var list<string> the idempotency keys the gateway passed on setup creation, in order */
    public array $setupIdempotencyKeys = [];

    /** @var list<?string> the mandate ids the gateway passed on intent creation, in order */
    public array $intentMandateIds = [];

    /** @var array<string, list<array{id: string, brand: string, last4: string, expMonth: ?int, expYear: ?int, isDefault: bool}>> */
    private array $methods = [];

    public function __construct(
        private string $status = 'paid',
        private string $id = 'tr_fake',
        private bool $fail = false,
        private string $refundStatus = 'refunded',
        private string $refundId = 're_fake',
        private string $intentStatus = 'open',
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

    public function createIntent(string $amount, string $currency, string $account, string $reference, string $idempotencyKey, ?string $mandateId): array
    {
        $this->intentIdempotencyKeys[] = $idempotencyKey;
        $this->intentMandateIds[] = $mandateId;

        if ($this->fail) {
            throw new MollieChargeFailed('intent_failed');
        }

        return ['id' => 'tr_intent', 'status' => $this->intentStatus, 'checkoutUrl' => 'https://checkout.mollie.test/'.$idempotencyKey];
    }

    public function createSetup(string $account, string $idempotencyKey): array
    {
        $this->setupIdempotencyKeys[] = $idempotencyKey;

        if ($this->fail) {
            throw new MollieChargeFailed('setup_failed');
        }

        return ['id' => 'tr_setup', 'status' => $this->intentStatus, 'checkoutUrl' => 'https://checkout.mollie.test/setup/'.$idempotencyKey];
    }

    public function listMethods(string $account): array
    {
        return $this->methods[$account] ?? [];
    }

    public function attachMethod(string $account, string $mandateId): array
    {
        if ($this->fail) {
            throw new MollieChargeFailed('attach_failed');
        }

        // The first mandate attached to a customer becomes its default.
        $isDefault = ($this->methods[$account] ?? []) === [];

        $method = [
            'id' => $mandateId,
            'brand' => 'Visa',
            'last4' => '4242',
            'expMonth' => 12,
            'expYear' => 2030,
            'isDefault' => $isDefault,
        ];

        $this->methods[$account][] = $method;

        return $method;
    }

    public function setDefaultMethod(string $account, string $mandateId): void
    {
        if ($this->fail) {
            throw new MollieChargeFailed('set_default_failed');
        }

        $this->methods[$account] = array_map(
            static function (array $method) use ($mandateId): array {
                $method['isDefault'] = $method['id'] === $mandateId;

                return $method;
            },
            $this->methods[$account] ?? [],
        );
    }
}
