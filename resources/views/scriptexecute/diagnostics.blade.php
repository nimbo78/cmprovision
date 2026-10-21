{{-- Read-only utility-OS snapshot; paths can be replaced by the shell fixture tests. --}}
DIAG_SYS=${CMP_DIAG_SYS:-/sys}
DIAG_PROC=${CMP_DIAG_PROC:-/proc}
DIAG_FILE=${CMP_DIAG_TMP:-/tmp}/cmprovision-storage-diagnostics.txt
DIAG_SERIAL={!! escapeshellarg($serial) !!}
DIAG_TARGET={!! escapeshellarg($storage ?: '/dev/mmcblk0') !!}
DIAG_TOKEN={!! escapeshellarg($diagnosticsToken ?? '') !!}
DIAG_URL={!! escapeshellarg('http://'.$server.'/scriptexecute?serial='.rawurlencode($serial).'&diagnostics=1') !!}

cmp_diag_text() { if [ -r "$1" ]; then tr -d '\000\r\n' < "$1" | cut -c1-160; fi; }
# The legacy and minimal USB images may have no timeout applet. Bound this one firmware read
# in a subshell, without changing or killing any pre-existing target process.
cmp_diag_power() (
    umask 077
    diag_power_file=$DIAG_FILE.power
    : > "$diag_power_file" || return 0
    vcgencmd get_throttled > "$diag_power_file" 2>/dev/null &
    diag_power_pid=$!
    diag_ticks=0
    while kill -0 "$diag_power_pid" 2>/dev/null && [ "$diag_ticks" -lt 20 ]; do
        sleep 0.1
        diag_ticks=$((diag_ticks + 1))
    done
    if kill -0 "$diag_power_pid" 2>/dev/null; then
        kill -9 "$diag_power_pid" 2>/dev/null
    fi
    if ! kill -0 "$diag_power_pid" 2>/dev/null; then wait "$diag_power_pid" 2>/dev/null; fi
    head -c 160 "$diag_power_file"
    rm -f "$diag_power_file"
)
cmp_diag_collect() (
    umask 077
    diag_name=${DIAG_TARGET##*/}
    diag_path=$DIAG_SYS/class/block/$diag_name
    diag_host=$(readlink -f "$diag_path/device" 2>/dev/null | sed -n 's#^.*/mmc_host/\(mmc[0-9]*\)/.*#\1#p')
    [ -n "$diag_host" ] || { [ "$diag_name" != mmcblk0 ] || diag_host=mmc0; }
    diag_block=missing; [ ! -b "$DIAG_TARGET" ] || diag_block=present
    diag_controller=unknown
    if [ -n "$diag_host" ]; then
        diag_controller=missing
        [ ! -d "$DIAG_SYS/class/mmc_host/$diag_host" ] || diag_controller=present
    fi
    {
        echo CMPROVISION_DIAGNOSTICS_V1
        echo "context=$1"
        echo "serial=$DIAG_SERIAL"
        echo "target=$DIAG_TARGET"
        echo "kernel=$(uname -r)"
        echo "model=$(cmp_diag_text "$DIAG_PROC/device-tree/model")"
        echo "block=$diag_block"
        echo "host=$diag_host"
        echo "controller=$diag_controller"
        echo "sectors=$(cmp_diag_text "$diag_path/size")"
        echo "readonly=$(cmp_diag_text "$diag_path/ro")"
        for diag_field in type name cid csd manfid oemid fwrev life_time pre_eol_info; do
            case "$diag_field" in type) diag_key=card_type;; name) diag_key=card_name;; *) diag_key=$diag_field;; esac
            echo "$diag_key=$(cmp_diag_text "$diag_path/device/$diag_field")"
        done
        diag_power=$(cmp_diag_power 2>/dev/null)
        case "$diag_power" in throttled=0x*) echo "$diag_power";; *) echo throttled=unknown;; esac
        echo '[partitions]'
        head -c 2048 "$DIAG_PROC/partitions" 2>/dev/null; echo
        echo '[mmc_ios]'
        [ -z "$diag_host" ] || head -c 2048 "$DIAG_SYS/kernel/debug/$diag_host/ios" 2>/dev/null
        echo
        echo '[kernel]'
        dmesg 2>/dev/null | grep -Ev 'Kernel command line:|Command line:' \
            | grep -Ei 'mmc[0-9]|mmcblk|sdhci|nvme|I/O error|under.?voltage|Voltage normal|usb.*(error|disconnect|reset)|sd [0-9]:' \
            | tail -n 80 | cut -c1-240 || true
    } > "$DIAG_FILE" || return 1
    return 0
)
# Never hold up provisioning for a diagnostic upload, and never change the target storage.
cmp_diag_report() {
    [ -n "$DIAG_TOKEN" ] || return 0
    cmp_diag_collect "$1" || return 0
    curl --silent --show-error --fail --connect-timeout 2 --max-time 5 -g \
        --form-string "diagnostics_token=$DIAG_TOKEN" -F "diagnostics=@$DIAG_FILE" "$DIAG_URL" \
        || echo 'Storage diagnostics could not be delivered; the local snapshot is in /tmp/cmprovision-storage-diagnostics.txt'
    return 0
}
