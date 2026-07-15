<?php

declare(strict_types=1);

namespace Cbox\Billing\Mollie\Exceptions;

use RuntimeException;

/**
 * A Mollie API call to create a payment failed. The gateway turns this into a
 * failed PaymentResult rather than letting it propagate.
 */
class MollieChargeFailed extends RuntimeException {}
