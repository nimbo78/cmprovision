#!/bin/sh
{{-- USB preparation (2.12): the bootloader and nothing else. The module booted over USB, takes the image of the bootloader
     the server made (network boot first), flashes it and shows "ready"; the operator unplugs it and puts it on the PoE
     switch, where the stand writes the image. Needs: $cm, $server, $diagnosticsToken, $storage, $flash, $flashName. --}}
set -o pipefail

export SERIAL="{{ $cm->serial }}"
export SERVER="{{ $server }}"

echo "USB prep: the bootloader only, no image is written"
@include('scriptexecute.diagnostics', ['serial' => $cm->serial])
cmp_diag_report startup

# Deliver a report to the provisioning server, retrying for up to 10 minutes (see scriptexecute.blade.php)
report() {
    for attempt in $(seq 1 60); do
        curl --silent --show-error -g --connect-timeout 10 --max-time 120 --retry 2 --retry-connrefused "$@" && return 0
        echo "Report to server failed (attempt $attempt of 60), retrying in 10 seconds"
        sleep 10
    done
    return 1
}

# Live status for the web interface; best effort
progress_mark() {
    curl --silent --output /dev/null --connect-timeout 2 --max-time 4 -G "http://{{ $server }}/scriptexecute" \
        --data-urlencode "serial={{ $cm->serial }}" --data-urlencode "progress=$1" \
        --data-urlencode "detail=$2" --data-urlencode "sectors=$3" --data-urlencode "durable_mb=$4" || true
}

@include('scriptexecute.leds', ['serial' => $cm->serial])
led_init

# The bootloader as it is: booted by rpiboot the module runs the one sent over USB, so the version comes from the flash chip
echo Querying and registering EEPROM version
flashrom -p "linux_spi:dev=/dev/spidev0.0,spispeed=16000" -r "/tmp/pieeprom.bin" || true
strings /tmp/pieeprom.bin |grep VERSION: >/tmp/eeprom_version
strings /tmp/pieeprom.bin |grep BUILD_TIMESTAMP= >>/tmp/eeprom_version
: >/tmp/eeprom_config
report -F 'eeprom_version=@/tmp/eeprom_version' -F 'eeprom_config=@/tmp/eeprom_config' "http://{{ $server }}/scriptexecute?serial={{ $cm->serial }}"

cat >/tmp/pre-0.sh << "CMPROVISIONINGEOF"
{!! $flash !!}
CMPROVISIONINGEOF
progress_mark preinstall {!! escapeshellarg($flashName) !!}
echo "===" >> /tmp/pre.log
echo "Running pre-installation script '{{ $flashName }}'" >> /tmp/pre.log
echo "===" >> /tmp/pre.log
sh -v /tmp/pre-0.sh >>/tmp/pre.log 2>&1
RETCODE=$?
if [ $RETCODE -ne 0 ]; then
    echo "Flashing the bootloader failed."
    report -F 'log=@/tmp/pre.log' "http://{{ $server }}/scriptexecute?serial={{ $cm->serial }}&retcode=$RETCODE&phase=preinstall"
    finish fail
fi
report -F 'log=@/tmp/pre.log' "http://{{ $server }}/scriptexecute?serial={{ $cm->serial }}&retcode=0&phase=preinstall"

# flashrom is done with the SPI bus: ACT can show the result
led_take_act

TEMP=`vcgencmd measure_temp`
report "http://{{ $server }}/scriptexecute?serial={{ $cm->serial }}&alldone=1&temp=${TEMP:5}"

echo ""
echo "====="
echo "Bootloader ready: the board boots from the network first."
echo "Unplug the USB cable and the power, then plug the board into the PoE switch."

finish done
