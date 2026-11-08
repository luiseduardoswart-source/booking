<?php

namespace App\Engines\Exceptions;

use App\Enums\BookingStatus;
use RuntimeException;

class InvalidStatusTransitionException extends RuntimeException
{
    public function __construct(BookingStatus $from, BookingStatus $to)
    {
        parent::__construct("Status transition from '{$from->label()}' to '{$to->label()}' is not allowed.");
    }
}
