<?php

namespace App\Engines\Exceptions;

use InvalidArgumentException;

/**
 * There is nothing (more) to pay for this booking: the amount is 0 or the
 * booking is cancelled. Only this means "no checkout needed"; any other
 * error, also an InvalidArgumentException of the payment provider's own
 * library (a key it refuses), is a checkout that could not be made.
 */
class NothingToPayException extends InvalidArgumentException {}
