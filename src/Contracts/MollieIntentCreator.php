<?php

declare(strict_types=1);

namespace Cbox\Billing\Mollie\Contracts;

use Cbox\Billing\Mollie\Exceptions\MollieChargeFailed;

/**
 * The seam over the Mollie SDK: create a payment for a charge, refund a
 * previously-captured amount, and the client-side intent / stored-method operations the
 * embedded (SCA-aware) integration needs. Isolating the SDK makes the gateway's mapping
 * logic unit-testable without the network. The amount is a decimal string (Mollie's
 * format), e.g. "125.00".
 *
 * `$idempotencyKey` is a caller-scoped external idempotency key passed straight to
 * Mollie (the SDK's `Idempotency-Key` header): if the process crashes between the API
 * call and recording its result, a retry with the same key returns the original
 * payment (or refund) instead of creating a second one, so both steps are resume-safe.
 *
 * A saved payment method in Mollie is a customer mandate; the intent/method operations
 * return normalised arrays (never SDK objects) so the gateway owns the mapping onto the
 * engine's value objects. Card data (PAN/CVC) never crosses this seam — only the
 * non-sensitive display fields Mollie exposes on a mandate. `checkoutUrl` is Mollie's
 * hosted-checkout redirect (its analogue of a client secret) the frontend sends the
 * customer to; it is null for an off-session recurring charge that needs no redirect.
 */
interface MollieIntentCreator
{
    /**
     * @return array{id: string, status: string}
     *
     * @throws MollieChargeFailed
     */
    public function create(string $amount, string $currency, string $reference, string $idempotencyKey): array;

    /**
     * Refund `$amount` (a decimal string) against the original Mollie payment (`tr_…`),
     * scoped by the idempotency key so a retry or a re-delivered webhook collapses to one
     * refund.
     *
     * @return array{id: string, status: string}
     *
     * @throws MollieChargeFailed
     */
    public function refund(string $amount, string $currency, string $paymentId, string $idempotencyKey): array;

    /**
     * Create an ON-SESSION Mollie payment for `$account` (the Mollie customer) charging
     * `$amount`, returning its id, status and hosted-checkout `checkoutUrl`. When
     * `$mandateId` is set the payment is charged `recurring` against that saved mandate
     * (no redirect); otherwise the customer completes it on the checkout. Scoped by
     * `$idempotencyKey` so a retried creation collapses to a single Mollie payment.
     *
     * @return array{id: string, status: string, checkoutUrl: ?string}
     *
     * @throws MollieChargeFailed
     */
    public function createIntent(string $amount, string $currency, string $account, string $reference, string $idempotencyKey, ?string $mandateId): array;

    /**
     * Create an OFF-SESSION `first`-sequence payment for `$account` so completing it
     * establishes a mandate later renewals charge, returning the id, status and
     * `checkoutUrl`. Scoped by `$idempotencyKey` so a retry collapses to one payment.
     *
     * @return array{id: string, status: string, checkoutUrl: ?string}
     *
     * @throws MollieChargeFailed
     */
    public function createSetup(string $account, string $idempotencyKey): array;

    /**
     * The card mandates saved for `$account`, mapped to the seam's display shape, with the
     * mandate Mollie would charge (the first `valid` one) flagged as default.
     *
     * @return list<array{id: string, brand: string, last4: string, expMonth: ?int, expYear: ?int, isDefault: bool}>
     *
     * @throws MollieChargeFailed
     */
    public function listMethods(string $account): array;

    /**
     * Return the mandate `$mandateId` on `$account` as the seam's display shape. In Mollie
     * a mandate is created when the customer completes the setup payment, so this confirms
     * and returns the already-vaulted method rather than creating a new attachment.
     *
     * @return array{id: string, brand: string, last4: string, expMonth: ?int, expYear: ?int, isDefault: bool}
     *
     * @throws MollieChargeFailed
     */
    public function attachMethod(string $account, string $mandateId): array;

    /**
     * Mark `$mandateId` the default for `$account`. Mollie has no default-mandate endpoint
     * (recurring charges auto-select the first valid mandate), so the real adapter has
     * nothing to persist; the seam exists so the gateway routes uniformly and a fake can
     * simulate a reassignable vault.
     *
     * @throws MollieChargeFailed
     */
    public function setDefaultMethod(string $account, string $mandateId): void;

    /**
     * Create a Mollie customer (`cst_…`) that saved mandates and off-session charges attach
     * to, stamping the host's stable `$account` key into the customer's metadata
     * (`['account' => $account]`) so the object reconciles back from the Mollie dashboard.
     * Returns the new customer id. A creation that never reached Mollie surfaces as a
     * failure — a customer that was not created must never be returned.
     *
     * @throws MollieChargeFailed
     */
    public function createCustomer(string $account, ?string $email, ?string $name): string;

    /**
     * Revoke the mandate `$mandateId` on customer `$customerId` — a saved Mollie "payment
     * method" for recurring billing IS a mandate, so tearing one down means revoking it.
     * Idempotent: a mandate Mollie reports as already revoked or unknown (HTTP 410 / 404)
     * is treated as success; any other failure surfaces.
     *
     * @throws MollieChargeFailed
     */
    public function revokeMandate(string $customerId, string $mandateId): void;
}
