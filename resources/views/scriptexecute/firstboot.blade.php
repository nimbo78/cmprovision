#!/bin/sh
# cmprovision: the OS check after the first boot (2.6.0, docs/superpowers/specs/2026-10-15-os-check-design.md).
# The provisioning server's last post-install step put this here with cmprovision-firstboot.service. It runs once, as
# root: waits for the boot to finish, installs the project's packages, runs its first-boot scripts, reports the host
# name and the result to the server, removes itself and shows the result on the LEDs until the next boot.
# Tests point it elsewhere with the FIRSTBOOT_* variables and LEDS.
SERVER={!! escapeshellarg($server) !!}
SERIAL={!! escapeshellarg($serial) !!}
DIR=${FIRSTBOOT_DIR:-/usr/local/lib/cmprovision-firstboot}
UNIT=${FIRSTBOOT_UNIT:-/etc/systemd/system/cmprovision-firstboot.service}
WANTS=${FIRSTBOOT_WANTS:-/etc/systemd/system/multi-user.target.wants/cmprovision-firstboot.service}
LOG=${FIRSTBOOT_LOG:-/var/log/cmprovision-firstboot.log}
HOSTNAME_FILE=${FIRSTBOOT_HOSTNAME:-/proc/sys/kernel/hostname}
LEDS=${LEDS:-/sys/class/leds}
SETTLE=${FIRSTBOOT_SETTLE:-300}
SCRIPT_SECONDS=${FIRSTBOOT_SCRIPT_SECONDS:-120}
TRIES=${FIRSTBOOT_TRIES:-60}
PAUSE=${FIRSTBOOT_PAUSE:-10}
GRACE=${FIRSTBOOT_GRACE:-1}
export SERVER

: > "$LOG"
log() { printf '%s\n' "$*" >> "$LOG"; }   # printf: dash's echo would turn \\ into \
RESULT=0
REASON=
# the first failure decides the result; what follows it is skipped
fail() {
    [ "$RESULT" -eq 0 ] || return 0
    RESULT=1
    REASON=$1
    log "OS check failed: $1"
}

# 1. The end of the boot: "degraded" (some unit failed) is common and no failure by itself
log "=== waiting for the boot to finish (at most $SETTLE s)"
timeout "$SETTLE" systemctl is-system-running --wait > /dev/null 2>&1
STATE=$(systemctl is-system-running 2>/dev/null)
log "systemd: ${STATE:-unknown}"

# 2. The project's packages from the server: checked, then installed with one apt-get. The stand has no internet, so
#    apt downloads nothing: what they depend on is in the image or among them
get_package() {   # get_package <name> <url> <sha256> <file>
    i=0
    while [ "$i" -lt "$TRIES" ]; do
        curl -fsS --connect-timeout 10 --max-time 600 -o "$4" "$2" >> "$LOG" 2>&1 && break
        i=$((i + 1))
        sleep "$PAUSE"
    done
    if [ ! -s "$4" ]; then
        fail "package $1: download failed"
        return 1
    fi
    echo "$3  $4" | sha256sum -c >> "$LOG" 2>&1 || { fail "package $1: SHA256 mismatch"; return 1; }
}
@if (count($packages))
log "=== packages"
mkdir -p "$DIR/packages"
@foreach ($packages as $package)
[ "$RESULT" -ne 0 ] || get_package {!! escapeshellarg($package->filename) !!} {!! escapeshellarg('http://'.$server.'/uploads/packages/'.$package->filename_on_server) !!} {!! escapeshellarg($package->sha256) !!} "$DIR/packages/{{ $loop->iteration }}.deb"
@endforeach
if [ "$RESULT" -eq 0 ]; then
    DEBIAN_FRONTEND=noninteractive apt-get install -y --no-download -o DPkg::Lock::Timeout=120 "$DIR"/packages/*.deb >> "$LOG" 2>&1
    rc=$?
    [ "$rc" -eq 0 ] || fail "package install failed: apt-get exited $rc"
fi
@endif

# 3. The project's first-boot scripts in its order, each with a time limit; the first failure stops them
run_script() {   # run_script <file> <name>
    [ "$RESULT" -eq 0 ] || return 0
    log "=== first boot script '$2'"
    timeout "$SCRIPT_SECONDS" sh -v "$1" >> "$LOG" 2>&1
    rc=$?
    if [ "$rc" -eq 124 ]; then
        fail "first boot script '$2' timed out after $SCRIPT_SECONDS s"
    elif [ "$rc" -ne 0 ]; then
        fail "first boot script '$2' exited $rc"
    fi
}
@foreach ($scripts as $script)
run_script "$DIR/scripts/{{ $script->id }}.sh" {!! escapeshellarg($script->name) !!}
@endforeach

# 4. What the system is like
HOST=$(cat "$HOSTNAME_FILE" 2>/dev/null)
FAILED=$(systemctl --failed --plain --no-legend --no-pager 2>/dev/null | awk '{ print $1 }' | tr '\n' ' ')
log "host name: ${HOST:-unknown}; failed units: ${FAILED:-none}"

# 5. One report, retried as the utility OS retries its reports; the text fields go as text (--form-string)
sent=no
i=0
while [ "$i" -lt "$TRIES" ]; do
    if curl -fsS --connect-timeout 10 --max-time 120 -F "log=@$LOG" --form-string "hostname=$HOST" \
            --form-string "state=$STATE" --form-string "failed_units=$FAILED" --form-string "reason=$REASON" \
            "http://$SERVER/scriptexecute?serial=$SERIAL&firstboot=1&retcode=$RESULT" > /dev/null 2>> "$LOG"; then
        sent=yes
        break
    fi
    i=$((i + 1))
    sleep "$PAUSE"
done
if [ "$sent" = no ]; then
    RESULT=1
    log "the report did not reach the server"
fi

# 6. Once only: the unit and its link go now, this directory once the helper runs; the log stays
rm -f "$WANTS" "$UNIT"

# 7. The result on the LEDs until the next boot: ready (both fade in and out together) or fail, by the helper the
#    utility OS had, else by kernel triggers
MODE=ready
[ "$RESULT" -eq 0 ] || MODE=fail
if [ -x "$DIR/ledpattern" ] && "$DIR/ledpattern" check 2>/dev/null; then
    "$DIR/ledpattern" "$MODE" &
    LED_PID=$!
    sleep "$GRACE"   # the helper runs before its file goes
    rm -rf "$DIR"
    wait "$LED_PID"
    exit 0
fi
rm -rf "$DIR"
ACT=$LEDS/led0; [ -e "$LEDS/ACT" ] && ACT=$LEDS/ACT
PWR=$LEDS/led1; [ -e "$LEDS/PWR" ] && PWR=$LEDS/PWR
led() {   # led <led> none <0|1> | <trigger>
    [ -e "$1/trigger" ] || return 0
    echo "$2" > "$1/trigger"
    [ "$2" != none ] || echo "$3" > "$1/brightness"
}
if [ "$MODE" = ready ]; then
    led "$ACT" none 1
    led "$PWR" none 1
else
    led "$ACT" none 0
    led "$PWR" heartbeat
fi
exit 0
