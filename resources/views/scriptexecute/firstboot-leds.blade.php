{{-- Post-report LED loop only: no packages, scripts or OS-check report. Included by firstboot. --}}
# Keep only the LED helper in volatile storage: the firstboot directory may contain secrets.
LED_POLL_SECONDS=${LED_POLL_SECONDS:-5}
LED_IDENTIFY_POLL_SECONDS=${LED_IDENTIFY_POLL_SECONDS:-2}
IDENTIFY_RUN={!! escapeshellarg($identify_run ?? '') !!}
LED_PID=
LED_RUNTIME=
LEDPATTERN=
led_stop() {
    if [ -n "$LED_PID" ]; then
        kill "$LED_PID" 2>/dev/null
        wait "$LED_PID" 2>/dev/null
        LED_PID=
    fi
}
led_cleanup() {
    led_stop
    [ -z "$LED_RUNTIME" ] || rm -rf "$LED_RUNTIME"
}
trap led_cleanup EXIT
trap 'exit 0' HUP INT TERM
if [ -x "$DIR/ledpattern" ] && "$DIR/ledpattern" check 2>/dev/null; then
    LED_RUNTIME=$(mktemp -d "${FIRSTBOOT_RUNTIME_BASE:-/run}/cmprovision-leds.XXXXXX")
    if [ -n "$LED_RUNTIME" ] && cp "$DIR/ledpattern" "$LED_RUNTIME/ledpattern" && chmod 700 "$LED_RUNTIME/ledpattern"; then
        LEDPATTERN=$LED_RUNTIME/ledpattern
    fi
fi
rm -f "$WANTS" "$UNIT"
rm -rf "$DIR"

ACT=$LEDS/led0; [ -e "$LEDS/ACT" ] && ACT=$LEDS/ACT
PWR=$LEDS/led1; [ -e "$LEDS/PWR" ] && PWR=$LEDS/PWR
led_set() {   # led_set <led> none <0|1> | timer <on ms> <off ms> | <trigger>
    [ -e "$1/trigger" ] || return 0
    echo "$2" > "$1/trigger"
    case "$2" in
        none) echo "$3" > "$1/brightness" ;;
        timer) echo "$3" > "$1/delay_on"; echo "$4" > "$1/delay_off" ;;
    esac
}
led_mode() {
    led_stop
    if [ -n "$LEDPATTERN" ]; then
        "$LEDPATTERN" "$1" &
        LED_PID=$!
    else
        case "$1" in
            ready) led_set "$ACT" none 1; led_set "$PWR" none 1 ;;
            fail) led_set "$ACT" none 0; led_set "$PWR" heartbeat ;;
            identify) led_set "$ACT" timer 150 150; led_set "$PWR" timer 150 150 ;;
        esac
    fi
}
MODE=ready
[ "$RESULT" -eq 0 ] || MODE=fail
CURRENT=$MODE
led_mode "$CURRENT"
# Poll immediately, so the controls become available as soon as the report is accepted. A failed
# request (including a partial response) means the result, never a latched Identify pattern.
while :; do
    NEXT=$MODE
    if ANSWER=$(curl -fsS --connect-timeout 2 --max-time 4 \
            "http://$SERVER/scriptexecute?serial=$SERIAL&identify=poll&identify_run=$IDENTIFY_RUN" 2>/dev/null) \
            && [ "$ANSWER" = identify ]; then
        NEXT=identify
    fi
    if [ "$NEXT" != "$CURRENT" ]; then
        led_mode "$NEXT"
        CURRENT=$NEXT
    fi
    INTERVAL=$LED_POLL_SECONDS
    [ "$CURRENT" != identify ] || INTERVAL=$LED_IDENTIFY_POLL_SECONDS
    sleep "$INTERVAL" || break
done
