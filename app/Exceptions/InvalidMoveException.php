<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class InvalidMoveException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'invalid_move')
    {
        parent::__construct($message);
    }
}
