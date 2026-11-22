<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class BlockchainGatewayException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'UNKNOWN_ERROR',
        public readonly ?string $txHash = null,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
