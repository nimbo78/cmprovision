<?php

return [

    /*
    |--------------------------------------------------------------------------
    | EEPROM firmware store
    |--------------------------------------------------------------------------
    |
    | Bootloader images selectable in a project live in <firmware_dir>/<channel>/pieeprom-*.bin.
    | They are fetched from the raspberrypi/rpi-eeprom repository on GitHub and, when the
    | rpi-eeprom package is installed, picked up from its directory as well.
    |
    */

    'firmware_dir' => env('CMPROVISION_FIRMWARE_DIR', storage_path('app/firmware')),

    'system_firmware_dir' => env('CMPROVISION_SYSTEM_FIRMWARE_DIR', '/usr/lib/firmware/raspberrypi/bootloader-2711'),

];
