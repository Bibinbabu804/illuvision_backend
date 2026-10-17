<?php

namespace App\Domain\Pricing\Exceptions;

use Exception;

class PricingException extends Exception
{
    public function __construct(string $message = 'Pricing error', int $code = 422)
    {
        parent::__construct($message, $code);
    }
}
