<?php

namespace App\Exceptions;

use RuntimeException;

class WidergyException extends RuntimeException
{
    public function __construct(string $message, public readonly array $context = [])
    {
        parent::__construct($message);
    }
}
