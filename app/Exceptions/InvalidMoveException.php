<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * A move that breaks the game rules (not your turn, illegal side…), answered with 422.
 */
final class InvalidMoveException extends ApiException
{
    public function __construct(string $message, string $errorCode = 'invalid_move')
    {
        parent::__construct($message, $errorCode);
    }
}
