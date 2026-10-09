<?php

namespace App\Services\Snmp;

class SnmpError extends \RuntimeException
{
    /** @var bool the agent did not answer at all (no point asking it anything else) */
    public $timeout;

    public function __construct($message, $timeout = false)
    {
        parent::__construct($message);
        $this->timeout = $timeout;
    }
}
