{{-- The module's LEDs, for the provisioning script and for the server's refusal and error scripts.
     Defines functions only: the caller runs led_init first. Paths are variables for the tests. --}}
# LEDs: ACT by the Ethernet jack, PWR next to it. Patterns, chosen with an operator on a WLAN Pi M4+:
#   progress  ACT blinks 10 times a second, PWR on (kernel timer)
#   done      ACT and PWR take turns, each fading in and out
#   fail      ACT dim, PWR flashes twice, then a second dark
#   identify  ACT and PWR alternate fast while the operator wants it (web interface: Identify, Stop)
# Fading and dimming come from the ledpattern helper (software PWM) downloaded from the server;
# without it the same states are shown with kernel triggers.
LEDS=${LEDS:-/sys/class/leds}
LEDS_DRIVER=${LEDS_DRIVER:-/sys/bus/platform/drivers/leds-gpio}
LED_SPI_DRIVER=${LED_SPI_DRIVER:-/sys/bus/platform/drivers/spi-bcm2835}
LED_TMP=${LED_TMP:-/tmp}
LEDPATTERN=$LED_TMP/ledpattern
LED_POLL_SECONDS=${LED_POLL_SECONDS:-5}
LED_IDENTIFY_POLL_SECONDS=${LED_IDENTIFY_POLL_SECONDS:-2}
LED_PID=""
LED_POLL_URL={!! escapeshellarg('http://'.$server.'/scriptexecute?serial='.rawurlencode($serial).'&identify=poll'.(!empty($diagnosticsToken) ? '&diagnostics_token='.$diagnosticsToken : '')) !!}
led_paths() {
    LED_ACT=$LEDS/led0; [ -e "$LEDS/ACT" ] && LED_ACT=$LEDS/ACT
    LED_PWR=$LEDS/led1; [ -e "$LEDS/PWR" ] && LED_PWR=$LEDS/PWR
}
led_paths

# Fetch the helper (best effort: without it only the fading is lost) and show "in progress"
led_fetch_helper() {
    if ! { curl --silent --max-time 20 -g -o "$LEDPATTERN" "http://{{ $server }}/tools/ledpattern" \
           && chmod +x "$LEDPATTERN" && "$LEDPATTERN" check; }; then
        rm -f "$LEDPATTERN"
    fi
}

led_init() { led_fetch_helper; led_mode progress; }

led_set() {   # led_set <led> none <0|1> | timer <on ms> <off ms> | <other trigger>
    [ -e "$1/trigger" ] || return 0
    echo "$2" > "$1/trigger"
    case "$2" in
        none) echo "$3" > "$1/brightness" ;;
        timer) echo "$3" > "$1/delay_on"; echo "$4" > "$1/delay_off" ;;
    esac
}

# ACT shares GPIO 42 with the SPI bus flashrom uses for the EEPROM (overlay spi-gpio40-45): the pin
# stays with SPI until its controller is unbound. New kernels refuse to bind the LED driver at
# all while SPI owns the pin. Release SPI only after flashrom is no longer needed, then bind LEDs.
led_take_act() {
    [ -e "$LED_TMP/led-act-taken" ] && return 0
    if [ -e "$LED_SPI_DRIVER/fe204000.spi" ]; then
        echo fe204000.spi > "$LED_SPI_DRIVER/unbind" || return 0
    fi
    if [ -e "$LEDS_DRIVER/leds" ]; then
        echo leds > "$LEDS_DRIVER/unbind" || return 0
    fi
    if [ -e "$LEDS_DRIVER/bind" ]; then
        echo leds > "$LEDS_DRIVER/bind" || return 0
    fi
    led_paths
    # The initial check may have rejected the helper because neither LED existed before the bind.
    [ -x "$LEDPATTERN" ] || led_fetch_helper
    led_mode progress
    : > "$LED_TMP/led-act-taken"
}

led_mode() {   # led_mode progress|done|fail|identify
    if [ -n "$LED_PID" ]; then
        kill "$LED_PID" 2>/dev/null
        wait "$LED_PID" 2>/dev/null
        LED_PID=""
    fi
    if [ "$1" = progress ]; then
        led_set "$LED_PWR" none 1
        led_set "$LED_ACT" timer 50 50
        return 0
    fi
    led_take_act
    if [ -x "$LEDPATTERN" ]; then
        "$LEDPATTERN" "$1" &
        LED_PID=$!
        return 0
    fi
    case "$1" in
        done) led_set "$LED_ACT" timer 1500 1500; led_set "$LED_PWR" timer 1500 1500 ;;
        fail) led_set "$LED_ACT" none 0; led_set "$LED_PWR" heartbeat ;;
        identify) led_set "$LED_ACT" timer 150 150; led_set "$LED_PWR" timer 150 150 ;;
    esac
}

led_asked() {   # does the operator want this module to identify itself?
    LED_REPLY=$(curl --silent --max-time 4 -g "$LED_POLL_URL")
    if [ "$LED_REPLY" = diagnostics ] && command -v cmp_diag_report >/dev/null 2>&1; then
        cmp_diag_report manual
        return 1
    fi
    [ "$LED_REPLY" = identify ]
}

# Blink for the operator as long as the server says so (it stops by itself after a while, or when the
# operator presses Stop), then return to the final state
led_poll_once() {   # led_poll_once done|fail
    led_asked || return 0
    led_mode identify
    while sleep "$LED_IDENTIFY_POLL_SECONDS" && led_asked; do :; done
    led_mode "$1"
}

# Every run ends here, successful or not: the module shows its result and keeps asking the server
# whether to identify itself, until it is switched off. Never returns.
finish() {   # finish done|fail
    led_mode "$1"
    if command -v cmp_diag_report >/dev/null 2>&1; then
        case "$1" in fail) cmp_diag_report failure;; done) cmp_diag_report complete;; esac
    fi
    while true; do
        sleep "$LED_POLL_SECONDS"
        led_poll_once "$1"
    done
}
