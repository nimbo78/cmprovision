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

    /*
    |--------------------------------------------------------------------------
    | dnsmasq
    |--------------------------------------------------------------------------
    |
    | The Settings page rewrites the dhcp-host= lines of this file from the hosts table and
    | restarts dnsmasq with this command (allowed without password by debian/010_cmprovision).
    |
    */

    'dnsmasq_conf' => env('CMPROVISION_DNSMASQ_CONF', base_path('etc/dnsmasq.conf')),

    'dnsmasq_restart' => env('CMPROVISION_DNSMASQ_RESTART', 'sudo -n /bin/systemctl restart cmprovision-dnsmasq'),

];
