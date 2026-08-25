<?php

namespace GenderScope;

use RuntimeException;

class GenderScopeException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0, public readonly ?array $body = null)
    {
        parent::__construct($message, $status);
    }
}
