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

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | The Telegram Bot API; the load rehearsal points it to a stand-in (tests/load).
    |
    */

    'telegram_api' => env('TELEGRAM_API', 'https://api.telegram.org'),

    /*
    |--------------------------------------------------------------------------
    | Image pieces (2.6.1)
    |--------------------------------------------------------------------------
    |
    | A project with "Split the image into pieces" keeps a gzip copy of its image in pieces of this many MiB; a
    | module restarted mid-write continues from its last verified piece. Making the copy needs free space of
    | about the original's size times split_space_factor.
    |
    */

    'split_piece_mb' => (int) env('CMPROVISION_SPLIT_PIECE_MB', 512),

    'split_space_factor' => 1.25,

    /* Utility OS directories and the link followed by dnsmasq and rpiboot (2.7.0). */
    'boot_root' => env('CMPROVISION_BOOT_ROOT', base_path()),
    'boot_link' => env('CMPROVISION_BOOT_LINK', base_path('etc/boot')),

    /* The file the Laravel log window of Settings shows the end of. */
    'laravel_log' => storage_path('logs/laravel.log'),

    /* Images from the internet (2.8.0). A fetch runs in slices of this many seconds (less than the queue's retry_after, 90 s),
       so the checksums and pieces copies of other images take their turn between them. */
    'image_download_slice' => (int) env('CMPROVISION_IMAGE_DOWNLOAD_SLICE', 45),
    /* the largest file a fetch accepts, GB, and the free space (GiB) that must stay after it */
    'image_download_max_gb' => (int) env('CMPROVISION_IMAGE_DOWNLOAD_MAX_GB', 40),
    'image_reserve_gb' => (int) env('CMPROVISION_IMAGE_RESERVE_GB', 2),
    /* tests only: allows plain http to loopback addresses (a local test server); nothing in production sets it */
    'image_test_loopback' => false,

];
