#!/bin/sh
# Root-only backup/restore library. Load it before a restore can reinstall an older package.
# No application database is opened here; no value from a snapshot becomes a command or path.

cmprovision_time_snapshot()
{
    cmp_time_presence=absent
    if [ -e /etc/systemd/timesyncd.conf.d/90-cmprovision.conf ] || [ -L /etc/systemd/timesyncd.conf.d/90-cmprovision.conf ]; then cmp_time_presence=present; fi
    cmp_time_enabled=$(/usr/bin/systemctl is-enabled systemd-timesyncd.service 2>/dev/null || true)
    cmp_time_active=$(/usr/bin/systemctl is-active systemd-timesyncd.service 2>/dev/null || true)
    case "$cmp_time_enabled:$cmp_time_active" in
        enabled:active|enabled:inactive|disabled:active|disabled:inactive) cmp_time_state="$cmp_time_enabled:$cmp_time_active" ;;
        *) cmp_time_state=unmanaged ;;
    esac
    printf '1\n%s\n%s\n' "$cmp_time_presence" "$cmp_time_state" > "$1/time-settings.saved"
}

cmprovision_time_restore()
{
    [ "${2:-0}" = 0 ] || return 0
    [ -f "$1/time-settings.saved" ] && [ ! -L "$1/time-settings.saved" ] || return 0
    cmp_time_record=$(head -c 129 "$1/time-settings.saved")
    cmp_time_version=$(printf '%s\n' "$cmp_time_record" | sed -n '1p')
    cmp_time_presence=$(printf '%s\n' "$cmp_time_record" | sed -n '2p')
    cmp_time_state=$(printf '%s\n' "$cmp_time_record" | sed -n '3p')
    if [ "${#cmp_time_record}" -gt 128 ] || [ "$cmp_time_version" != 1 ] || [ "$(printf '%s\n' "$cmp_time_record" | wc -l)" -ne 3 ]; then
        echo 'Invalid time snapshot record; time service state was not restored.' >&2; return 0
    fi
    case "$cmp_time_presence" in present|absent) ;; *) echo 'Invalid time snapshot presence.' >&2; return 0 ;; esac
    case "$cmp_time_state" in enabled:active|enabled:inactive|disabled:active|disabled:inactive|unmanaged) ;;
        *) echo 'Invalid time snapshot service state.' >&2; return 0 ;; esac
    if [ "$cmp_time_presence" = absent ]; then
        rm -f /etc/systemd/timesyncd.conf.d/90-cmprovision.conf
    elif [ ! -e /etc/systemd/timesyncd.conf.d/90-cmprovision.conf ]; then
        echo 'The saved NTP configuration is missing; time service state was not restored.' >&2; return 0
    fi
    # timedated may already be running with the old /etc/localtime cached in memory.
    /usr/bin/systemctl try-restart systemd-timedated.service 2>/dev/null || true
    [ "$(/usr/bin/systemctl show systemd-timesyncd.service --property=LoadState --value 2>/dev/null)" = loaded ] || return 0
    case "$(/usr/bin/systemctl is-enabled systemd-timesyncd.service 2>/dev/null || true)" in
        enabled|disabled) ;; *) echo 'The time service is masked or unsupported; its saved state was not applied.' >&2; return 0 ;;
    esac
    for cmp_time_other in chrony.service chronyd.service ntp.service ntpsec.service openntpd.service; do
        if [ "$(/usr/bin/systemctl is-active "$cmp_time_other" 2>/dev/null || true)" = active ]; then
            echo 'Another NTP client is running; its service state was not changed.' >&2; return 0
        fi
    done
    if [ "$cmp_time_state" = unmanaged ]; then
        /usr/bin/systemctl try-restart systemd-timesyncd.service || echo 'Could not refresh the time service.' >&2
        return 0
    fi
    case "$cmp_time_state" in enabled:*) cmp_time_enable=enable ;; *) cmp_time_enable=disable ;; esac
    case "$cmp_time_state" in *:active) cmp_time_run=restart ;; *) cmp_time_run=stop ;; esac
    /usr/bin/systemctl "$cmp_time_enable" systemd-timesyncd.service || { echo 'Could not restore NTP enablement.' >&2; return 0; }
    /usr/bin/systemctl "$cmp_time_run" systemd-timesyncd.service || echo 'Could not restore the NTP running state.' >&2
}
