<?php

namespace App\Events;

use App\Models\Cm;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/* A module received its provisioning script */
class CmProvisioningStarted
{
    use Dispatchable, SerializesModels;

    public $cm;

    public function __construct(Cm $cm)
    {
        $this->cm = $cm;
    }
}
