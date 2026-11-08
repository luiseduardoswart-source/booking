<?php

namespace App\Engines\Exceptions;

use RuntimeException;

class UnavailableForBookingException extends RuntimeException
{
    /**
     * @param  string[]  $reasons
     */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct('This period is not available: '.implode(' ', $reasons));
    }
}
