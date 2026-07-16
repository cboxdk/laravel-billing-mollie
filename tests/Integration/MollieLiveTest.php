<?php

declare(strict_types=1);

use Cbox\Billing\Mollie\MollieApiIntentCreator;
use Cbox\Billing\Mollie\MolliePaymentGateway;
use Cbox\Billing\Payment\Webhook\Storage\InMemorySettledPaymentStore;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\Http\ResponseStatusCode;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\Customer;
use Mollie\Api\Resources\Mandate;

/*
|--------------------------------------------------------------------------
| Live Mollie sandbox lifecycle
|--------------------------------------------------------------------------
|
| These tests drive the REAL Mollie API path — MollieApiIntentCreator +
| MolliePaymentGateway over a genuine MollieApiClient — against Mollie's
| test mode, so the adapter's stored-customer + mandate behaviour is proven
| against Mollie and not only against the FakeMollieIntentCreator.
|
| They are gated on MOLLIE_TEST_KEY (a `test_…` key, deliberately distinct
| from a production MOLLIE_KEY so the two can never collide). With no key
| set the whole file skips cleanly, so `composer qa` / `vendor/bin/pest`
| stay green with a skip reported, and the suite is excluded from CI. Run
| it explicitly with:
|
|   MOLLIE_TEST_KEY=test_… vendor/bin/pest --group=integration
|
| Coverage is HEADLESS end-to-end: a `directdebit` mandate is created
| directly via Mollie's customer-mandates API (test IBAN), which needs no
| redirect. The `first`-sequence setup-payment path
| (MolliePaymentGateway::createSetupIntent) is NOT exercised here — it
| returns a hosted-checkout URL a human must complete in a browser, so it
| cannot run unattended. That gap is documented, not faked.
|
*/

uses()->group('integration');

// Gate the whole file on the sandbox key: with none set every test reports as skipped, so
// `composer qa` / `vendor/bin/pest` stay green (with skips) and CI never needs a secret.
beforeEach(function (): void {
    if (! env('MOLLIE_TEST_KEY')) {
        test()->markTestSkipped('set MOLLIE_TEST_KEY (a test_… key) to run the live Mollie sandbox suite');
    }
});

/**
 * The Mollie test IBAN documented for creating a headless directdebit mandate in test
 * mode. See https://docs.mollie.com/docs/testing.
 */
const TEST_IBAN = 'NL55INGB0000000000';

/**
 * A raw Mollie client built from the sandbox test key. A `test_…` key puts every request
 * in test mode automatically, so no throwaway object touches live money.
 */
function liveClient(): MollieApiClient
{
    $key = env('MOLLIE_TEST_KEY');

    if (! is_string($key) || ! str_starts_with($key, 'test_')) {
        throw new RuntimeException('MOLLIE_TEST_KEY must be a Mollie test_… key.');
    }

    $client = new MollieApiClient;
    $client->setApiKey($key);

    return $client;
}

/**
 * The real gateway wired exactly as the service provider wires it in production, but over
 * the sandbox client and an in-memory settle store.
 */
function liveGateway(MollieApiClient $client): MolliePaymentGateway
{
    return new MolliePaymentGateway(
        new MollieApiIntentCreator($client, 'https://example.test/billing/return'),
        new InMemorySettledPaymentStore,
    );
}

/**
 * Create a headless `directdebit` mandate on the customer using the test IBAN. This is the
 * cleanest documented way to establish a usable mandate in test mode without a redirect.
 */
function createTestMandate(MollieApiClient $client, string $customerId): Mandate
{
    return $client->mandates->createForId($customerId, [
        'method' => 'directdebit',
        'consumerName' => 'Ada Lovelace',
        'consumerAccount' => TEST_IBAN,
    ]);
}

it('drives the full stored-customer + mandate lifecycle against Mollie test mode', function () {
    $client = liveClient();
    $gateway = liveGateway($client);
    $account = 'DK-'.bin2hex(random_bytes(4));

    // createCustomer → a cst_ reference, with the host account stamped into metadata.
    $customerId = $gateway->createCustomer($account, 'ada@example.test', 'Ada Lovelace');

    expect($customerId)->toStartWith('cst_');

    try {
        $customer = $client->customers->get($customerId);

        expect($customer)->toBeInstanceOf(Customer::class)
            ->and($customer->metadata)->not->toBeNull()
            ->and(($customer->metadata->account ?? null))->toBe($account);

        // Establish a mandate headlessly (directdebit + test IBAN) → a mdt_ id.
        $mandate = createTestMandate($client, $customerId);

        expect($mandate->id)->toStartWith('mdt_')
            ->and($mandate->isInvalid())->toBeFalse();

        // detachPaymentMethod(customer, mandate) revokes the mandate.
        $gateway->detachPaymentMethod($customerId, $mandate->id);

        expect(mandateIsGoneOrInvalid($client, $customerId, $mandate->id))->toBeTrue();

        // Idempotent: revoking the already-revoked mandate again is a no-op (Mollie 410/404),
        // so the gateway must not throw.
        $gateway->detachPaymentMethod($customerId, $mandate->id);

        expect(mandateIsGoneOrInvalid($client, $customerId, $mandate->id))->toBeTrue();
    } finally {
        // Clean up the throwaway customer (this also tears down its mandates).
        deleteCustomerQuietly($client, $customerId);
    }
});

it('treats detaching an unknown mandate as an idempotent no-op (Mollie 404)', function () {
    $client = liveClient();
    $gateway = liveGateway($client);
    $account = 'DK-'.bin2hex(random_bytes(4));

    $customerId = $gateway->createCustomer($account);

    try {
        // No mandate was ever created for this customer; revoking a fabricated id must be a
        // clean no-op rather than an error — this is the fully-headless idempotency proof.
        $gateway->detachPaymentMethod($customerId, 'mdt_0000000000');

        expect(true)->toBeTrue();
    } finally {
        deleteCustomerQuietly($client, $customerId);
    }
});

/**
 * Whether the mandate is now revoked — either Mollie no longer returns it (410 Gone / 404
 * Not Found) or it comes back with status `invalid`. Both are the intended end state after
 * a revoke.
 */
function mandateIsGoneOrInvalid(MollieApiClient $client, string $customerId, string $mandateId): bool
{
    try {
        $mandate = $client->mandates->getForId($customerId, $mandateId);
    } catch (ApiException $e) {
        return in_array($e->getCode(), [ResponseStatusCode::HTTP_GONE, ResponseStatusCode::HTTP_NOT_FOUND], true);
    }

    return $mandate->isInvalid();
}

/**
 * Best-effort teardown of a test customer — a cleanup failure must not mask the assertion
 * result, so a Mollie error here is swallowed.
 */
function deleteCustomerQuietly(MollieApiClient $client, string $customerId): void
{
    try {
        $client->customers->delete($customerId);
    } catch (ApiException) {
        // Ignore: the customer is a throwaway test object; leaving it in the sandbox is
        // harmless and must not turn a green assertion red.
    }
}
