#!/bin/sh
set -o pipefail

export SERIAL="{{ $cm->serial }}"
export SERVER="{{ $server }}"
export STORAGE="{{ $storage }}"
export PART1="{{ $part1 }}"
export PART2="{{ $part2 }}"

# Deliver a report (log, EEPROM version, completion) to the provisioning server, retrying for up to
# 10 minutes: the server or the network may be unavailable for a moment, and a lost report leaves
# the operator with a silent module.
report() {
    for attempt in $(seq 1 60); do
        curl --silent --show-error -g --connect-timeout 10 --max-time 120 --retry 2 --retry-connrefused "$@" && return 0
        echo "Report to server failed (attempt $attempt of 60), retrying in 10 seconds"
        sleep 10
    done
    return 1
}

# Live status for the web interface: the phase (and script) the module is in and, while the image is
# written or verified, how many sectors went to or came from the storage device. Best effort: short
# timeouts and no retries, so a slow or missing answer never holds up provisioning.
STORAGE_STAT=/sys/block/$(basename $STORAGE)/stat
progress_mark() {
    curl --silent --output /dev/null --connect-timeout 2 --max-time 4 -G "http://{{ $server }}/scriptexecute" \
        --data-urlencode "serial={{ $cm->serial }}" --data-urlencode "progress=$1" \
        --data-urlencode "detail=$2" --data-urlencode "sectors=$3" --data-urlencode "durable_mb=$4" || true
}
# progress_start <phase> <field of $STORAGE_STAT: 7 = sectors written, 3 = sectors read> [<detail> [<sectors before>]]:
# a write that goes on after a restart keeps its step (detail) and counts on from where it was; the marks also carry how
# far the write is durable (write_pieces in scriptexecute/write.blade.php)
progress_start() {
    progress_mark "$1" "$3"
    [ -r "$STORAGE_STAT" ] || return 0
    PROGRESS_BASE=$(awk -v f="$2" '{print $f}' "$STORAGE_STAT")
    ( while sleep 5; do
          progress_mark "$1" "$3" "$(( ${4:-0} + $(awk -v f="$2" '{print $f}' "$STORAGE_STAT") - PROGRESS_BASE ))" \
              "$(cat "${WORK:-/tmp}/durable" 2>/dev/null)"
      done ) &
    PROGRESS_PID=$!
}
# progress_note <bytes> <of bytes> <curl exit code>: the download broke and goes on from that byte (a line in the log)
progress_note() {
    curl --silent --output /dev/null --connect-timeout 2 --max-time 4 -G "http://{{ $server }}/scriptexecute" \
        --data-urlencode "serial={{ $cm->serial }}" --data-urlencode "progress=write" \
        --data-urlencode "resumed=$1" --data-urlencode "of=$2" --data-urlencode "curl=$3" || true
}
progress_stop() {
    if [ -n "$PROGRESS_PID" ]; then kill "$PROGRESS_PID" 2>/dev/null; PROGRESS_PID=""; fi
}

@include('scriptexecute.leds', ['serial' => $cm->serial])
led_init

# Make sure we have random entropy
echo "{{Str::random(64)}}" >/dev/urandom

@foreach ( $preinstall_scripts as $script )
# Writing script '{{$script->name}}' to /tmp/pre-{{$script->id}}.sh
cat >/tmp/pre-{{$script->id}}.sh << "CMPROVISIONINGEOF"
{!! $script->script !!}
CMPROVISIONINGEOF
@endforeach
@foreach ( $postinstall_scripts as $script )
# Writing script '{{$script->name}}' to /tmp/post-{{$script->id}}.sh
cat >/tmp/post-{{$script->id}}.sh << "CMPROVISIONINGEOF"
{!! $script->script !!}
CMPROVISIONINGEOF
@endforeach
@if ($os_check)
# The OS check (Check the OS after the first boot): its service, what it runs, and the step that puts them into the
# written system, last of the post-install steps
mkdir -p /tmp/oscheck/scripts
cat >/tmp/oscheck/run << "CMPROVISIONINGEOF"
{!! $firstboot_run !!}
CMPROVISIONINGEOF
cat >/tmp/oscheck/cmprovision-firstboot.service << "CMPROVISIONINGEOF"
[Unit]
Description=cmprovision: check this system on its first boot and report to the provisioning server
Wants=network-online.target
After=network-online.target

[Service]
Type=simple
ExecStart=/bin/sh /usr/local/lib/cmprovision-firstboot/run

[Install]
WantedBy=multi-user.target
CMPROVISIONINGEOF
@foreach ($firstboot_scripts as $script)
cat >/tmp/oscheck/scripts/{{ $script->id }}.sh << "CMPROVISIONINGEOF"
{!! $script->script !!}
CMPROVISIONINGEOF
@endforeach
cat >/tmp/oscheck-install.sh << "CMPROVISIONINGEOF"
{!! $oscheck_install !!}
CMPROVISIONINGEOF
@endif

# The bootloader as it is before provisioning: the EEPROM flash, if the project has one, is the first
# pre-install script. Booted by rpiboot (boot mode 3) the module runs the bootloader sent over USB, so
# the version comes from the flash chip itself, without the settings.
echo Querying and registering EEPROM version
@if ($bootmode == 3)
flashrom -p "linux_spi:dev=/dev/spidev0.0,spispeed=16000" -r "/tmp/pieeprom.bin" || true
strings /tmp/pieeprom.bin |grep VERSION: >/tmp/eeprom_version
strings /tmp/pieeprom.bin |grep BUILD_TIMESTAMP= >>/tmp/eeprom_version
: >/tmp/eeprom_config
@else
vcgencmd bootloader_version >/tmp/eeprom_version || true
vcgencmd bootloader_config >/tmp/eeprom_config || true
@endif
report -F 'eeprom_version=@/tmp/eeprom_version' -F 'eeprom_config=@/tmp/eeprom_config' "http://{{ $server }}/scriptexecute?serial={{ $cm->serial }}"

@if ( count($preinstall_scripts) )
echo "Running pre-install scripts"
@foreach ( $preinstall_scripts as $script )
progress_mark preinstall {!! escapeshellarg($script->name) !!}
echo "===" >> /tmp/pre.log
echo "Running pre-installation script '{{ $script->name }}'" >> /tmp/pre.log
echo "===" >> /tmp/pre.log
sh -v /tmp/pre-{{$script->id}}.sh >>/tmp/pre.log 2>&1{!! $script->bg ? ' &' : '' !!}
RETCODE=$?
if [ $RETCODE -ne 0 ]; then
    echo "Pre-installation script failed."
    report -F 'log=@/tmp/pre.log' "http://{{ $server }}/scriptexecute?serial={{ $cm->serial }}&retcode=$RETCODE&phase=preinstall"
    finish fail
fi
@endforeach
report -F 'log=@/tmp/pre.log' "http://{{ $server }}/scriptexecute?serial={{ $cm->serial }}&retcode=0&phase=preinstall"
@endif

# flashrom is done with the SPI bus (the EEPROM flash is a pre-install script): ACT can show progress
led_take_act
led_mode progress

@if ($image_url)
@include('scriptexecute.write')
write_image
RETCODE=$?
if [ $RETCODE -eq 0 ]; then
    echo Original image written successfully
else
    echo Writing image failed.
    # The partition table goes last, so a failed write leaves none: on the next power cycle the module returns to network
    # boot (provisioning). Cleared again in case the write failed after it.
    dd if=/dev/zero of=$STORAGE bs=1M count=1 conv=fsync,notrunc 2>/dev/null || true
    report -F 'log=@/tmp/dd.log' "http://{{ $server }}/scriptexecute?serial={{ $cm->serial }}&retcode=$RETCODE&phase=dd"
    finish fail
fi

partprobe $STORAGE
sleep 0.1
@endif

@if ( count($postinstall_scripts) || $os_check )
echo "Running post-install scripts"
@foreach ( $postinstall_scripts as $script )
@if ($script->progress == 'verify')
progress_start verify 3
@else
progress_mark postinstall {!! escapeshellarg($script->name) !!}
@endif
echo "===" >> /tmp/post.log
echo "Running post-installation script '{{ $script->name }}'" >> /tmp/post.log
echo "===" >> /tmp/post.log
sh -v /tmp/post-{{$script->id}}.sh >>/tmp/post.log 2>&1{!! $script->bg ? ' &' : '' !!}
RETCODE=$?
@if ($script->progress == 'verify')
progress_stop
@endif
if [ $RETCODE -ne 0 ]; then
    echo "Postinstallation script failed."
    report -F 'log=@/tmp/post.log' "http://{{ $server }}/scriptexecute?serial={{ $cm->serial }}&retcode=$RETCODE&phase=postinstall"
    finish fail
fi
@endforeach
@if ($os_check)
progress_mark postinstall 'Installing the OS check'
echo "===" >> /tmp/post.log
echo "Running post-installation script 'Installing the OS check'" >> /tmp/post.log
echo "===" >> /tmp/post.log
sh -v /tmp/oscheck-install.sh >>/tmp/post.log 2>&1
RETCODE=$?
if [ $RETCODE -ne 0 ]; then
    echo "Installing the OS check failed."
    report -F 'log=@/tmp/post.log' "http://{{ $server }}/scriptexecute?serial={{ $cm->serial }}&retcode=$RETCODE&phase=postinstall"
    finish fail
fi
@endif
report -F 'log=@/tmp/post.log' "http://{{ $server }}/scriptexecute?serial={{ $cm->serial }}&retcode=0&phase=postinstall"
@endif

TEMP=`vcgencmd measure_temp`
report "http://{{ $server }}/scriptexecute?serial={{ $cm->serial }}&alldone=1&temp=${TEMP:5}&verify={{ $project->verify }}"

@if ($os_check)
echo ""
echo "====="
echo "Image written: rebooting into the new system to check it"
sync
reboot -f
# still here a minute later: show it
sleep 60
finish fail
@else
echo ""
echo "====="
echo "Provisioning completed successfully!"

sleep 0.1
if [ -f /sys/kernel/config/usb_gadget/g1/UDC ]; then
    echo "" > /sys/kernel/config/usb_gadget/g1/UDC
fi

finish done
@endif
