<?php

declare(strict_types=1);

namespace Cbox\Billing\Mollie;

use Cbox\Billing\Mollie\Contracts\PaymentFetcher;
use Cbox\Billing\Mollie\Exceptions\MollieChargeFailed;
use Cbox\Billing\Mollie\Exceptions\MolliePaymentNotFound;
use Cbox\Billing\Mollie\ValueObjects\MollieWebhookEvent;
use Cbox\Billing\Payment\Contracts\WebhookVerifier;
use Cbox\Billing\Payment\Enums\WebhookEventType;
use Cbox\Billing\Payment\Exceptions\WebhookVerificationFailed;
use Cbox\Billing\Payment\ValueObjects\WebhookEvent;
use Cbox\Billing\Payment\ValueObjects\WebhookPayload;
use Mollie\Api\Exceptions\InvalidSignatureException;
use Mollie\Api\Webhooks\SignatureValidator;

/**
 * The Mollie-backed {@see WebhookVerifier}. Mollie sends two kinds of payment webhook, and this
 * verifier takes both:
 *
 *  - CLASSIC (what a payment's `webhookUrl` receives): a form POST whose only field is the
 *    payment id, `id=tr_…`. Mollie does not sign it. Its security model is that the body carries
 *    no state: the receiver fetches the payment from the API with its own key and acts on what
 *    the API says. A forged call can therefore only make us look a payment up; it cannot claim
 *    one is paid. A payment the account does not own is not found, and refused.
 *  - NEXT-GEN (a webhook subscription, `payment.*` events): JSON naming the payment in
 *    `entityId`, signed with an `X-Mollie-Signature: sha256=…` HMAC of the raw body. With a
 *    signing secret configured, a signed delivery must carry a valid signature, checked by the
 *    SDK's {@see SignatureValidator} before any API call; the payment is still fetched, because
 *    the fetch is the state we act on.
 *
 * Either way the event is built only from the fetched payment ({@see MollieWebhookEvent}), never
 * from the body. Deny-by-default: a body without a payment id, a signature that does not match,
 * or a payment the account does not have throws {@see WebhookVerificationFailed}. A failure to
 * reach Mollie at all propagates as {@see MollieChargeFailed}, so the host answers with a 5xx and
 * Mollie delivers again.
 */
readonly class MollieApiWebhookVerifier implements WebhookVerifier
{
    private const string PAYMENT_ID = '/^tr_[A-Za-z0-9]+$/';

    public function __construct(
        private ?SignatureValidator $validator,
        private PaymentFetcher $fetcher,
    ) {}

    /**
     * @throws WebhookVerificationFailed when the delivery cannot be proved
     * @throws MollieChargeFailed when Mollie could not be asked for the payment
     */
    public function verify(WebhookPayload $payload): WebhookEvent
    {
        $signature = $payload->header('X-Mollie-Signature');

        if ($signature !== null && $signature !== '') {
            $this->checkSignature($payload->body, $signature);
        }

        try {
            $payment = $this->fetcher->fetch($this->paymentId($payload->body));
        } catch (MolliePaymentNotFound) {
            throw new WebhookVerificationFailed('Mollie has no such payment for this account.');
        }

        return new MollieWebhookEvent(self::mapType($payment->status), $payment);
    }

    private function checkSignature(string $body, string $signature): void
    {
        if ($this->validator === null) {
            throw new WebhookVerificationFailed('A signed Mollie webhook arrived, but no signing secret is configured to check it.');
        }

        try {
            $valid = $this->validator->validatePayload($body, $signature);
        } catch (InvalidSignatureException $e) {
            throw new WebhookVerificationFailed($e->getMessage(), previous: $e);
        }

        if (! $valid) {
            throw WebhookVerificationFailed::unsigned();
        }
    }

    /**
     * Map a Mollie payment status onto the engine's narrow event type. Only `paid` carries the
     * paid effect; a terminal `expired`/`canceled`/`failed` maps to a failure notice. `open` and
     * `authorized` mean the customer must still act on the hosted checkout
     * ({@see WebhookEventType::RequiresAction}), and `pending` is an accepted charge that has not
     * settled yet ({@see WebhookEventType::Processing}).
     */
    private static function mapType(string $status): WebhookEventType
    {
        return match ($status) {
            'paid' => WebhookEventType::PaymentSettled,
            'expired', 'canceled', 'failed' => WebhookEventType::PaymentFailed,
            'open', 'authorized' => WebhookEventType::RequiresAction,
            'pending' => WebhookEventType::Processing,
            default => WebhookEventType::PaymentPending,
        };
    }

    /** The payment id from a classic form body (`id=tr_…`) or a next-gen JSON body (`entityId`). */
    private function paymentId(string $body): string
    {
        $id = null;
        $json = json_decode($body, true);

        if (is_array($json)) {
            $id = $json['entityId'] ?? null;
        } else {
            parse_str($body, $params);
            $id = $params['id'] ?? null;
        }

        if (! is_string($id) || preg_match(self::PAYMENT_ID, $id) !== 1) {
            throw new WebhookVerificationFailed('Mollie webhook payload does not name a payment.');
        }

        return $id;
    }
}
