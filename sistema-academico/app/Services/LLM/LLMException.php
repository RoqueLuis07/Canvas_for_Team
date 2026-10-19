<?php

namespace App\Services\LLM;

use RuntimeException;

class LLMException extends RuntimeException
{
    /**
     * @param  string  $errorCode  Categoría estable: timeout, provider_unavailable, rate_limited, invalid_response.
     */
    public function __construct(string $message, public readonly string $errorCode = 'provider_unavailable')
    {
        parent::__construct($message);
    }
}
