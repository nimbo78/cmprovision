# Shared read-only collector: embedded in generated scripts and copied into the utility OS.
DIAG_SYS=${CMP_DIAG_SYS:-/sys}
DIAG_PROC=${CMP_DIAG_PROC:-/proc}
DIAG_ETC=${CMP_DIAG_ETC:-/etc}
DIAG_FILE=${DIAG_FILE:-${CMP_DIAG_TMP:-/tmp}/cmprovision-module-diagnostics.txt}
DIAG_STATE=${CMP_DIAG_STATE:-${CMP_DIAG_TMP:-/tmp}/cmprovision-diagnostics}
DIAG_BOOT_ID=$(tr -d '\000\r\n' 2>/dev/null < "$DIAG_PROC/sys/kernel/random/boot_id")
cmp_diag_text() { if [ -r "$1" ]; then tr -d '\000\r\n' < "$1" | cut -c1-160; fi; }
# The legacy and minimal USB images may have no timeout applet. Bound this one firmware read
# in a subshell, without changing or killing any pre-existing target process.
cmp_diag_power() (
    umask 077
    diag_power_file=$DIAG_FILE.power
    : > "$diag_power_file" || return 0
    vcgencmd get_throttled > "$diag_power_file" 2>/dev/null &
    diag_power_pid=$!
    # Two seconds in all. A BusyBox built without fractional sleep rejects 0.1 at once, which would end the wait
    # immediately and kill a firmware query that is merely slow: it then waits in whole seconds.
    diag_nap=0.1; diag_limit=20
    sleep 0.1 2>/dev/null || { diag_nap=1; diag_limit=2; }
    diag_ticks=0
    while kill -0 "$diag_power_pid" 2>/dev/null && [ "$diag_ticks" -lt "$diag_limit" ]; do
        sleep "$diag_nap"
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
    diag_event=${DIAG_EVENT:-$(cmp_diag_uuid)}
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
        echo "boot_id=$DIAG_BOOT_ID"
        echo "event_id=$diag_event"
        echo "mac=$(cmp_diag_text "$DIAG_SYS/class/net/eth0/address")"
        echo "network_stage=$(cmp_diag_text "$DIAG_STATE/network-stage")"
        echo "last_http_code=$(cmp_diag_text "$DIAG_STATE/http-code")"
        echo "last_curl_exit=$(cmp_diag_text "$DIAG_STATE/curl-exit")"
        echo "serial=$DIAG_SERIAL"
        echo "target=$DIAG_TARGET"
        echo "kernel=$(uname -r)"
        echo "architecture=$(uname -m)"
        echo "utility_os=$(cmp_diag_text "$DIAG_ETC/cmprovision-uos")"
        echo "bootloader_version=$(cmp_diag_text "$DIAG_PROC/device-tree/chosen/bootloader/version")"
        diag_boot=$(od -An -tx1 -N4 "$DIAG_PROC/device-tree/chosen/bootloader/boot-mode" 2>/dev/null | tr -d ' \n')
        echo "boot_mode=$diag_boot"
        echo "uptime_seconds=$(awk '{printf "%.0f", $1}' "$DIAG_PROC/uptime" 2>/dev/null)"
        echo "mem_total_kb=$(awk '$1 == "MemTotal:" {print $2; exit}' "$DIAG_PROC/meminfo" 2>/dev/null)"
        echo "mem_available_kb=$(awk '$1 == "MemAvailable:" {print $2; exit}' "$DIAG_PROC/meminfo" 2>/dev/null)"
        # Only a named CPU/SoC sensor; do not mistake a peripheral sensor for CPU temperature.
        for diag_zone in "$DIAG_SYS"/class/thermal/thermal_zone*; do
            diag_sensor=$(cmp_diag_text "$diag_zone/type")
            case "$diag_sensor" in cpu-thermal|soc-thermal|cpu_thermal) ;; *) continue;; esac
            echo "thermal_sensor=$diag_sensor"
            echo "temperature_mc=$(cmp_diag_text "$diag_zone/temp")"
            diag_trip=
            for diag_trip_file in "$diag_zone"/trip_point_*_temp; do
                case "$(cmp_diag_text "${diag_trip_file%_temp}_type")" in passive|hot|critical) ;; *) continue;; esac
                diag_trip_value=$(cmp_diag_text "$diag_trip_file")
                case "$diag_trip_value" in ''|*[!0-9]*) continue;; esac
                if [ "$diag_trip_value" -gt 0 ] && { [ -z "$diag_trip" ] || [ "$diag_trip_value" -lt "$diag_trip" ]; }; then
                    diag_trip=$diag_trip_value
                fi
            done
            echo "thermal_trip_mc=$diag_trip"
            break
        done
        diag_eth=$DIAG_SYS/class/net/eth0
        if [ -d "$diag_eth" ]; then echo ethernet=present; else echo ethernet=missing; fi
        for diag_field in carrier operstate speed duplex; do
            case "$diag_field" in speed) diag_key=eth_speed_mbps;; *) diag_key=eth_$diag_field;; esac
            echo "$diag_key=$(cmp_diag_text "$diag_eth/$diag_field" 2>/dev/null)"
        done
        for diag_field in rx_errors tx_errors rx_dropped tx_dropped; do
            echo "eth_$diag_field=$(cmp_diag_text "$diag_eth/statistics/$diag_field")"
        done
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
        echo '[network]'
        { ip -4 addr show dev eth0 2>/dev/null; ip -4 route show dev eth0 2>/dev/null; } | head -c 2048
        echo
        echo '[kernel]'
        dmesg 2>/dev/null | grep -Ev 'Kernel command line:|Command line:' \
            | grep -Ei 'mmc[0-9]|mmcblk|sdhci|nvme|I/O error|under.?voltage|Voltage normal|usb.*(error|disconnect|reset)|sd [0-9]:|thermal|overheat|Out of memory: Killed process|oom-kill:|invoked oom-killer|NETDEV WATCHDOG:|eth0.*(link|error|timeout|timed out)' \
            | tail -n 80 | cut -c1-240 || true
    } > "$DIAG_FILE" || return 1
    return 0
)
# Private RAM state lets the independent USB agent see a failed Ethernet delivery.
cmp_diag_uuid() { cmp_diag_text "$DIAG_PROC/sys/kernel/random/uuid"; }
cmp_diag_uptime() { awk '{printf "%.0f", $1}' "$DIAG_PROC/uptime" 2>/dev/null; }
cmp_diag_atomic() (
    umask 077
    mkdir -p "$DIAG_STATE" || return 1
    printf '%s\n' "$2" > "$DIAG_STATE/$1.$$" && mv -f "$DIAG_STATE/$1.$$" "$DIAG_STATE/$1"
)
cmp_diag_bind() {
    [ -n "${DIAG_TOKEN:-}" ] || return 0
    case "$(cmp_diag_text "$DIAG_STATE/binding")" in "$DIAG_TOKEN "*) ;; *) rm -f "$DIAG_STATE/ethernet-event";; esac
    cmp_diag_atomic binding "$DIAG_TOKEN $DIAG_TARGET"
}
# The upload's failure never changes provisioning. Only the fallback agent transfers over USB.
cmp_diag_report() {
    [ -n "${DIAG_TOKEN:-}" ] || return 0
    DIAG_EVENT=$(cmp_diag_uuid)
    cmp_diag_collect "$1" || return 0
    diag_collected=$(cmp_diag_uptime); diag_collected=${diag_collected:-0}
    if [ -n "$DIAG_EVENT" ]; then
        mkdir -p "$DIAG_STATE"
        (umask 077; cp "$DIAG_FILE" "$DIAG_STATE/pending-report.$$" && mv -f "$DIAG_STATE/pending-report.$$" "$DIAG_STATE/pending-report")
        cmp_diag_atomic pending "$DIAG_EVENT $1 $diag_collected $DIAG_TOKEN"
    fi
    if diag_http=$(curl --silent --show-error --fail --connect-timeout 2 --max-time 5 -g \
        --output /dev/null --write-out '%{http_code}' \
        --form-string "diagnostics_token=$DIAG_TOKEN" -F "diagnostics=@$DIAG_FILE" "$DIAG_URL") && [ "$diag_http" = 204 ]; then
        cmp_diag_atomic ethernet-event "$DIAG_TOKEN $DIAG_EVENT"
        case "$(cmp_diag_text "$DIAG_STATE/pending")" in "$DIAG_EVENT "*) rm -f "$DIAG_STATE/pending";; esac
    else
        echo 'Module diagnostics could not be delivered; the local snapshot is in /tmp/cmprovision-module-diagnostics.txt'
    fi
    return 0
}
