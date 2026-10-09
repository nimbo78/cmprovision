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
        --data-urlencode "detail=$2" --data-urlencode "sectors=$3" || true
}
# progress_start <phase> <field of $STORAGE_STAT: 7 = sectors written, 3 = sectors read>
progress_start() {
    progress_mark "$1"
    [ -r "$STORAGE_STAT" ] || return 0
    PROGRESS_BASE=$(awk -v f="$2" '{print $f}' "$STORAGE_STAT")
    ( while sleep 5; do
          progress_mark "$1" "" "$(( $(awk -v f="$2" '{print $f}' "$STORAGE_STAT") - PROGRESS_BASE ))"
      done ) &
    PROGRESS_PID=$!
}
progress_stop() {
    if [ -n "$PROGRESS_PID" ]; then kill "$PROGRESS_PID" 2>/dev/null; PROGRESS_PID=""; fi
}

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
    exit 1
fi
@endforeach
report -F 'log=@/tmp/pre.log' "http://{{ $server }}/scriptexecute?serial={{ $cm->serial }}&retcode=0&phase=preinstall"
@endif

@if ($image_url)
progress_mark write 'Discarding old data'
echo Sending BLKDISCARD to $STORAGE
blkdiscard -v $STORAGE || true

echo Writing image from {{ $image_url }} to $STORAGE
# Download, decompress and write in one stream. Every stage leaves its failure in /tmp/dd.log,
# which goes to the server when the pipeline fails (the progress meter of curl stays on the console).
: > /tmp/dd.log
progress_start write 7
{ curl --retry 10 -g "{{ $image_url }}"; RC=$?; [ $RC -eq 0 ] || echo "curl exit code $RC" >> /tmp/dd.log; exit $RC; } \
 | { {{ $decompress }} 2>> /tmp/dd.log; } \
 | dd of=$STORAGE conv=fsync obs=1M >> /tmp/dd.log 2>&1
RETCODE=$?
progress_stop
if [ $RETCODE -eq 0 ]; then
    echo Original image written successfully
else
    echo Writing image failed.
    # Do not leave a half-written image that looks bootable: clear the partition table so the
    # module returns to network boot (provisioning) on the next power cycle.
    dd if=/dev/zero of=$STORAGE bs=1M count=1 conv=fsync 2>/dev/null || true
    report -F 'log=@/tmp/dd.log' "http://{{ $server }}/scriptexecute?serial={{ $cm->serial }}&retcode=$RETCODE&phase=dd"
    exit 1
fi

partprobe $STORAGE
sleep 0.1
@endif

@if ( count($postinstall_scripts) )
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
    exit 1
fi
@endforeach
report -F 'log=@/tmp/post.log' "http://{{ $server }}/scriptexecute?serial={{ $cm->serial }}&retcode=0&phase=postinstall"
@endif

TEMP=`vcgencmd measure_temp`
report "http://{{ $server }}/scriptexecute?serial={{ $cm->serial }}&alldone=1&temp=${TEMP:5}&verify={{ $project->verify }}"

echo ""
echo "====="
echo "Provisioning completed successfully!"

sleep 0.1
if [ -f /sys/kernel/config/usb_gadget/g1/UDC ]; then
    echo "" > /sys/kernel/config/usb_gadget/g1/UDC
fi

if [ -e /sys/class/leds/led1 ]; then
    while true; do
        echo 255 > /sys/class/leds/led0/brightness
        echo 0 > /sys/class/leds/led1/brightness
        sleep 0.5
        echo 0 > /sys/class/leds/led0/brightness
        echo 255 > /sys/class/leds/led1/brightness
        sleep 0.5
    done
fi
if [ -e /sys/class/leds/led0 ]; then
    echo 255 > /sys/class/leds/led0/brightness
fi
