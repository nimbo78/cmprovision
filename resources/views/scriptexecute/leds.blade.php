{{-- The module's LEDs, for the provisioning script and for the server's refusal and error scripts.
     Defines functions only: the caller runs led_init first. Paths are variables for the tests. --}}
# LEDs: ACT by the Ethernet jack, PWR next to it. Patterns, chosen with an operator on a WLAN Pi M4+:
#   progress  ACT blinks 10 times a second, PWR on (kernel timer)
#   done      ACT and PWR take turns, each fading in and out
#   fail      ACT dim, PWR flashes twice, then a second dark
#   identify  ACT and PWR alternate fast, for 30 s when the operator asks in the web interface
# Fading and dimming come from the ledpattern helper (software PWM) downloaded from the server;
# without it the same states are shown with kernel triggers.
LEDS=${LEDS:-/sys/class/leds}
LEDS_DRIVER=${LEDS_DRIVER:-/sys/bus/platform/drivers/leds-gpio}
LED_TMP=${LED_TMP:-/tmp}
LEDPATTERN=$LED_TMP/ledpattern
LED_IDENTIFY_SECONDS=${LED_IDENTIFY_SECONDS:-30}
LED_POLL_SECONDS=${LED_POLL_SECONDS:-5}
LED_PID=""
LED_ACT=$LEDS/led0; [ -e "$LEDS/ACT" ] && LED_ACT=$LEDS/ACT
LED_PWR=$LEDS/led1; [ -e "$LEDS/PWR" ] && LED_PWR=$LEDS/PWR

# Fetch the helper (best effort: without it only the fading is lost) and show "in progress"
led_init() {
    if ! { curl --silent --max-time 20 -g -o "$LEDPATTERN" "http://{{ $server }}/tools/ledpattern" \
           && chmod +x "$LEDPATTERN" && "$LEDPATTERN" check; }; then
        rm -f "$LEDPATTERN"
    fi
    led_mode progress
}

led_set() {   # led_set <led> none <0|1> | timer <on ms> <off ms> | <other trigger>
    [ -e "$1/trigger" ] || return 0
    echo "$2" > "$1/trigger"
    case "$2" in
        none) echo "$3" > "$1/brightness" ;;
        timer) echo "$3" > "$1/delay_on"; echo "$4" > "$1/delay_off" ;;
    esac
}

# ACT shares GPIO 42 with the SPI bus flashrom uses for the EEPROM (overlay spi-gpio40-45): the pin
# stays with SPI, and ACT dark, until the LED driver is bound again. So only once flashrom is no
# longer needed: after the pre-install scripts, or when provisioning ends.
led_take_act() {
    [ -e "$LED_TMP/led-act-taken" ] && return 0
    if [ -e "$LEDS_DRIVER/leds" ]; then
        echo leds > "$LEDS_DRIVER/unbind"
        echo leds > "$LEDS_DRIVER/bind"
    fi
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

# Blink for the operator when the web interface asks for it, then return to the final state
led_poll_once() {   # led_poll_once done|fail
    if [ "$(curl --silent --max-time 4 -g "http://{{ $server }}/scriptexecute?serial={{ $serial }}&identify=poll")" = identify ]; then
        led_mode identify
        sleep "$LED_IDENTIFY_SECONDS"
        led_mode "$1"
    fi
}

# Every run ends here, successful or not: the module shows its result and keeps asking the server
# whether to identify itself, until it is switched off. Never returns.
finish() {   # finish done|fail
    led_mode "$1"
    while true; do
        sleep "$LED_POLL_SECONDS"
        led_poll_once "$1"
    done
}
