<?php

namespace App\Services;

/* The chat server asked to wait (HTTP 429): the channel is left alone for retryAfter seconds */
class RateLimited extends \RuntimeException
{
    public $retryAfter;

    public function __construct(int $retryAfter, string $message)
    {
        parent::__construct($message);
        $this->retryAfter = max(1, $retryAfter);
    }
}
