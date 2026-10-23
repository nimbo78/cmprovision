<?php

namespace App\Services;

/**
 * The pre-install script that flashes the bootloader: it fetches the image the server made (public/uploads), checks it
 * and writes it with flashrom. The project's flash (the active project's firmware and settings) and the USB preparation
 * (its own image) are the same script with another image.
 */
class EepromFlashScript
{
    public static function make(string $url, string $sha256): string
    {
        /* flashrom leaves the chip alone when it holds the image already; the last line says which it was
           (ScriptExecuteController::EEPROM_RESULT_LINE). The script runs under sh -v, which copies every script line into
           the log as well, so the result is printed from a variable and never spelled out in the script. */
        return "#!/bin/sh\n"
             . "set -e\n"
             . "curl --retry 10 --silent --show-error -g -o pieeprom.bin \"$url\"\n"
             . "echo \"$sha256  pieeprom.bin\" | sha256sum -c\n"
             . 'flashrom -p "linux_spi:dev=/dev/spidev0.0,spispeed=16000" -w "pieeprom.bin" >flashrom.log 2>&1 || { cat flashrom.log; exit 1; }'."\n"
             . "cat flashrom.log\n"
             . "if grep -q \"identical\" flashrom.log; then RESULT=identical; else RESULT=written; fi\n"
             . "echo \"EEPROM_RESULT=\$RESULT\"\n";
    }
}
