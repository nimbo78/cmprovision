/*
 * ledpattern: the LED patterns a module shows after provisioning, on the ACT and PWR LEDs of a
 * Compute Module. The LEDs are plain on/off GPIOs; fading and dimming come from software PWM
 * through their sysfs brightness files (200 frames a second, a LED is written only when its
 * state changes: PWR goes through the firmware's GPIO expander, every write is a mailbox call).
 * Runs until it is killed. The patterns were chosen with an operator on a WLAN Pi M4+.
 *
 *   ledpattern done       ACT and PWR take turns, each fading in and out, dark gap between
 *   ledpattern fail       ACT dim; PWR flashes twice within a second, then a second dark
 *   ledpattern identify   ACT and PWR alternate sharply every 0.15 s
 *   ledpattern check      exit status 0 when both LEDs can be written
 *
 * Built static for the 32-bit utility OS by build.sh; the module downloads it from the server
 * (public/tools/ledpattern). Without it the script shows the same states with kernel triggers.
 */
#include <fcntl.h>
#include <math.h>
#include <stdio.h>
#include <string.h>
#include <time.h>
#include <unistd.h>

#define FRAME_US 5000           /* 200 Hz */
#define DONE_CYCLE 3.0          /* seconds for both LEDs to take their turn */
#define DONE_BUMP 0.45          /* share of the cycle one LED's fade in and out takes */
#define FAIL_ACT_DUTY 0.06      /* dim */
#define FAIL_CYCLE 2.0
#define IDENTIFY_HALF 0.15

struct led { int fd; int on; const char *name; };

/* Linux 5.4 names the LEDs led0/led1, from 6.1 on ACT/PWR */
static int open_led(struct led *l, const char *old_name, const char *new_name)
{
    const char *names[] = { old_name, new_name };
    char path[128];
    for (int i = 0; i < 2; i++) {
        snprintf(path, sizeof path, "/sys/class/leds/%s/brightness", names[i]);
        l->fd = open(path, O_WRONLY);
        if (l->fd >= 0) {
            l->on = -1;
            l->name = names[i];
            return 1;
        }
    }
    return 0;
}

/* a kernel trigger would fight the pattern for the LED */
static void no_trigger(const struct led *l)
{
    char path[128];
    snprintf(path, sizeof path, "/sys/class/leds/%s/trigger", l->name);
    int t = open(path, O_WRONLY);
    if (t >= 0) {
        if (write(t, "none", 4) < 0) { /* the brightness file still works */ }
        close(t);
    }
}

static void set(struct led *l, int on)
{
    if (l->on != on && pwrite(l->fd, on ? "1" : "0", 1, 0) == 1)
        l->on = on;
}

static void add_us(struct timespec *t, long us)
{
    t->tv_nsec += us * 1000;
    while (t->tv_nsec >= 1000000000) { t->tv_nsec -= 1000000000; t->tv_sec++; }
}

static void sleep_to(const struct timespec *at)
{
    clock_nanosleep(CLOCK_MONOTONIC, TIMER_ABSTIME, at, NULL);
}

/* one smooth rise and fall in the first DONE_BUMP of the cycle, dark for the rest */
static double bump(double phase)
{
    phase -= floor(phase);
    if (phase >= DONE_BUMP)
        return 0;
    double s = sin(M_PI * phase / DONE_BUMP);
    return s * s * s * s;
}

/* share of the frame each LED is on, at t seconds */
static void duties(char mode, double t, double *act, double *pwr)
{
    double p;
    switch (mode) {
    case 'd':
        *act = bump(t / DONE_CYCLE);
        *pwr = bump(t / DONE_CYCLE + 0.5);
        break;
    case 'f':
        p = fmod(t, FAIL_CYCLE);
        *act = FAIL_ACT_DUTY;
        *pwr = (p < 0.2 || (p >= 0.5 && p < 0.7)) ? 1 : 0;
        break;
    default:
        p = fmod(t, 2 * IDENTIFY_HALF);
        *act = p < IDENTIFY_HALF ? 1 : 0;
        *pwr = 1 - *act;
    }
}

int main(int argc, char **argv)
{
    const char *mode = argc > 1 ? argv[1] : "";
    if (strcmp(mode, "done") && strcmp(mode, "fail") && strcmp(mode, "identify") && strcmp(mode, "check")) {
        fprintf(stderr, "usage: ledpattern done|fail|identify|check\n");
        return 2;
    }
    struct led act, pwr;
    if (!open_led(&act, "led0", "ACT") || !open_led(&pwr, "led1", "PWR")) {
        fprintf(stderr, "ledpattern: ACT and PWR LEDs not found in /sys/class/leds\n");
        return 1;
    }
    if (!strcmp(mode, "check"))
        return 0;
    no_trigger(&act);
    no_trigger(&pwr);

    struct timespec frame;
    clock_gettime(CLOCK_MONOTONIC, &frame);
    for (long n = 0;; n++) {
        double da, dp;
        duties(mode[0], n * (FRAME_US / 1e6), &da, &dp);
        long on_a = (long) (da * FRAME_US), on_p = (long) (dp * FRAME_US);
        set(&act, on_a > 0);
        set(&pwr, on_p > 0);
        /* the LED whose share of the frame ends first goes off first */
        struct led *first = on_a <= on_p ? &act : &pwr, *second = on_a <= on_p ? &pwr : &act;
        long end1 = on_a <= on_p ? on_a : on_p, end2 = on_a <= on_p ? on_p : on_a;
        if (end1 > 0 && end1 < FRAME_US) { struct timespec t = frame; add_us(&t, end1); sleep_to(&t); set(first, 0); }
        if (end2 > 0 && end2 < FRAME_US) { struct timespec t = frame; add_us(&t, end2); sleep_to(&t); set(second, 0); }
        add_us(&frame, FRAME_US);
        sleep_to(&frame);
    }
}
