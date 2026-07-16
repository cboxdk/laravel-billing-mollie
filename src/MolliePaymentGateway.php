<?php

declare(strict_types=1);

namespace Cbox\Billing\Mollie;

use Cbox\Billing\Mollie\Contracts\MollieIntentCreator;
use Cbox\Billing\Mollie\Exceptions\MollieChargeFailed;
use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Cbox\Billing\Payment\Contracts\SettledPaymentStore;
use Cbox\Billing\Payment\Enums\PaymentIntentStatus;
use Cbox\Billing\Payment\ValueObjects\PaymentIntent;
use Cbox\Billing\Payment\ValueObjects\PaymentIntentRequest;
use Cbox\Billing\Payment\ValueObjects\PaymentIntentResult;
use Cbox\Billing\Payment\ValueObjects\PaymentMethod;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;
use Cbox\Billing\Payment\ValueObjects\RefundIntent;
use Cbox\Billing\Payment\ValueObjects\SetupIntentRequest;
use Cbox\Billing\Payment\ValueObjects\SetupIntentResult;

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
        private string $profileId = '',
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
     * Create an on-session Mollie payment: the frontend sends the customer to the returned
     * hosted-checkout URL (carried as the client secret) to complete it — Strong Customer
     * Authentication happens there. No SDK call is hand-rolled here; the seam creates the
     * payment and the mapper normalises the status. `open` / `authorized` map to
     * {@see PaymentIntentStatus::RequiresAction}. An SDK failure
     * propagates as {@see MollieChargeFailed}: there is no failed intent status to return,
     * so on-session creation surfaces the error rather than claiming a state it never reached.
     */
    public function createPaymentIntent(PaymentIntentRequest $request): PaymentIntentResult
    {
        $result = $this->creator->createIntent(
            (string) $request->amount->toBrick()->getAmount(),
            $request->amount->currency(),
            $request->account,
            $request->reference,
            $request->idempotencyKey,
            $request->paymentMethodId,
        );

        return new PaymentIntentResult(
            gateway: $this->name(),
            publishableKey: $this->profileId(),
            clientSecret: $result['checkoutUrl'],
            status: $this->mapper->mapIntentStatus($result['status']),
            reference: $request->reference,
            amount: $request->amount,
        );
    }

    /**
     * Create an off-session setup: a `first`-sequence Mollie payment whose completion
     * establishes a mandate later renewals charge. The Mollie payment id is echoed as the
     * result reference for reconciliation.
     */
    public function createSetupIntent(SetupIntentRequest $request): SetupIntentResult
    {
        $result = $this->creator->createSetup($request->account, $request->idempotencyKey);

        return new SetupIntentResult(
            gateway: $this->name(),
            publishableKey: $this->profileId(),
            clientSecret: $result['checkoutUrl'],
            status: $this->mapper->mapIntentStatus($result['status']),
            reference: $result['id'],
        );
    }

    /**
     * @return list<PaymentMethod>
     */
    public function paymentMethods(string $account): array
    {
        return array_map(
            $this->toPaymentMethod(...),
            $this->creator->listMethods($account),
        );
    }

    public function attachPaymentMethod(string $account, string $paymentMethodId): PaymentMethod
    {
        return $this->toPaymentMethod($this->creator->attachMethod($account, $paymentMethodId));
    }

    public function setDefaultPaymentMethod(string $account, string $paymentMethodId): void
    {
        $this->creator->setDefaultMethod($account, $paymentMethodId);
    }

    /**
     * @param  array{id: string, brand: string, last4: string, expMonth: ?int, expYear: ?int, isDefault: bool}  $method
     */
    private function toPaymentMethod(array $method): PaymentMethod
    {
        return new PaymentMethod(
            id: $method['id'],
            brand: $method['brand'],
            last4: $method['last4'],
            expMonth: $method['expMonth'],
            expYear: $method['expYear'],
            isDefault: $method['isDefault'],
        );
    }

    /**
     * The configured Mollie profile id the frontend loads Mollie Components with, or null
     * when none is set — the result then carries no key rather than an empty string.
     */
    private function profileId(): ?string
    {
        return $this->profileId !== '' ? $this->profileId : null;
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
