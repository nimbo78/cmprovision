<?php

namespace App\Events;

use App\Models\Cm;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/* Provisioning of a module stopped: a script or the image write failed, or the server refused it.
   The reason is in $cm->phase_detail. */
class CmProvisioningFailed
{
    use Dispatchable, SerializesModels;

    public $cm;

    public function __construct(Cm $cm)
    {
        $this->cm = $cm;
    }
}
