<?php

declare(strict_types=1);

namespace Cbox\Billing\Mollie\Exceptions;

/**
 * Mollie answered that the account has no payment with this id (HTTP 404). For a webhook that
 * means the delivery names a payment this account never made: it is refused, not retried.
 */
class MolliePaymentNotFound extends MollieChargeFailed {}
