<?php

declare(strict_types=1);

namespace Cbox\Billing\Mollie\Exceptions;

use RuntimeException;

/**
 * A Mollie webhook payload failed signature verification (bad signature, a missing
 * signature where one is required, or a malformed body). The handler rejects the
 * delivery — deny-by-default: an unverified payload is never processed.
 */
class WebhookVerificationFailed extends RuntimeException {}
