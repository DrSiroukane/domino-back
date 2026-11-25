<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * An error the API answers with {message, code} and an HTTP status (rendered in
 * bootstrap/app.php). Throw it instead of building error JSON by hand; thrown
 * inside Room::withLock(), it also rolls the transaction back.
 */
class ApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }
}
