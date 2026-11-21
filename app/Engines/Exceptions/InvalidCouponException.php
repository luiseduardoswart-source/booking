<?php

namespace App\Engines\Exceptions;

use RuntimeException;

/**
 * A coupon code a guest entered that does not exist or cannot be used for
 * this booking. The message is the reason, in the guest's language.
 */
class InvalidCouponException extends RuntimeException {}
