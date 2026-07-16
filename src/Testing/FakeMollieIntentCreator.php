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

    /**
     * The Mollie customer minted per account key, keyed by account — mint-once, so a repeat
     * create for the same account resolves to the same `cst_…` reference.
     *
     * @var array<string, string>
     */
    public array $customers = [];

    /**
     * The customer metadata stamped on create, keyed by account, mirroring the real seam's
     * `['account' => …]` so a test can assert the account was recorded.
     *
     * @var array<string, array{email: ?string, name: ?string, account: string}>
     */
    public array $customerMetadata = [];

    /**
     * The (customerId, mandateId) pairs the gateway asked to revoke, in order — including
     * repeats, so a test can assert a second revoke of the same mandate stayed a no-op.
     *
     * @var list<array{customerId: string, mandateId: string}>
     */
    public array $revokedMandates = [];

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

    public function createCustomer(string $account, ?string $email, ?string $name): string
    {
        if ($this->fail) {
            throw new MollieChargeFailed('customer_create_failed');
        }

        // Mint once per account: a repeat create resolves to the same customer reference,
        // and the metadata is stamped from the first (and only) creation.
        if (! isset($this->customers[$account])) {
            $this->customers[$account] = 'cst_test_'.$account;
            $this->customerMetadata[$account] = ['email' => $email, 'name' => $name, 'account' => $account];
        }

        return $this->customers[$account];
    }

    public function revokeMandate(string $customerId, string $mandateId): void
    {
        if ($this->fail) {
            throw new MollieChargeFailed('revoke_failed');
        }

        // Record every call (repeats included) so idempotency is observable, then drop the
        // mandate from the vault. A second revoke of the same mandate finds nothing to drop
        // and stays a no-op — mirroring Mollie's 410/404-is-success semantics.
        $this->revokedMandates[] = ['customerId' => $customerId, 'mandateId' => $mandateId];

        $this->methods[$customerId] = array_values(array_filter(
            $this->methods[$customerId] ?? [],
            static fn (array $method): bool => $method['id'] !== $mandateId,
        ));
    }

    /**
     * Whether the mandate `$mandateId` is currently revoked for `$customerId` — i.e. no
     * longer present in the vault after at least one revoke. A test assertion helper.
     */
    public function isMandateRevoked(string $customerId, string $mandateId): bool
    {
        foreach ($this->revokedMandates as $revoked) {
            if ($revoked['customerId'] === $customerId && $revoked['mandateId'] === $mandateId) {
                return true;
            }
        }

        return false;
    }
}
