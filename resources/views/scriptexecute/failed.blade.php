#!/bin/sh
# The provisioning server cannot provision this module (the reason is in its log and on the console):
# show the failed state on the LEDs and wait for the operator.
echo {!! escapeshellarg($message) !!}
@include('scriptexecute.leds')
led_init
finish fail
