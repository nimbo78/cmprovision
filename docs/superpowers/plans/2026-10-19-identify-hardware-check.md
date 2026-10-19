# Identify after the OS check: stand verification, 19 October 2026

## Build and stand

- Server: `wlanpi-da2`, WLAN Pi M4+, upgraded from 2.6.3 to `2.6.4~dev1` after backup `/var/backups/cmprovision/20261019-005636-2.6.3` (73 MB).
- Final development package: `cmprovision4_2.6.4~dev1_all.deb`, SHA256 `7ae2553afdb03ec7757ba8bc17cfc03ee59228102135aed5734fa967c34ae37a`.
- Sensor: `100000004acecfa4`, `wlanpi-059`, `172.20.157.173`, already booted into its installed OS; its last OS check passed on 18 October at `16:06:51 UTC`.

## Preliminary check without erasing the OS

No erase, image write, reboot, package install or first-boot script was performed on the sensor. The original service was still showing `ready`; its executable was recovered from the running helper before stopping that service. Only the newly rendered **post-report LED loop** was started in a transient root service, `cmprovision-identify-bench.service`, bound to the existing provisioning start time. Its private input directory was removed by the loop; only the LED helper remained in `/run/cmprovision-leds.8PV6QT/`.

This first check verified the new loop on real hardware without rerunning provisioning. The subsequent full-cycle check is recorded below.

## Preliminary results

- Card at desktop width: Identify started one real `ledpattern identify` process; Stop replaced it with `ledpattern ready`.
- Card at 390 px: Identify and Stop performed the same transitions. A hostname label initially pushed the button outside the viewport; the final layout puts that label on a separate line.
- Dashboard: Identify started the same pattern. Without Stop, the server's 30-second request expired and the helper returned to `ready` (25.93 seconds after the test had observed the Identify process).
- Main loop PID stayed `18317`; helper PIDs changed on transitions, with exactly one helper at each observation. Final observed helper was `18705`, mode `ready`.
- OS-check timestamp remained `2026-10-18T16:06:51Z`. No repeated OS-check report was sent. Temporary browser sessions and SSH operation directories were removed.
- No browser JavaScript errors. `dpkg --audit` was empty; nginx, PHP-FPM, DHCP and both queues stayed active.
- Shell tests cover `fail` as well as `ready`, repeated Identify, HTTP/network/partial-response failure and recovery, fallback kernel LEDs, private runtime storage, termination cleanup, and removal of scripts/unit before polling. HTTP tests reject a previous run's heartbeat and preserve legacy utility-OS polling.
- Full PHP suite: 1132 tests, 3884 assertions, 21 existing SSH/SNMP environment skips, no failures. The final integration/documentation checks passed separately (9 tests / 64 assertions), as did the card/action checks after the layout change (21 / 82).

## Full re-provision check

After the user authorised the full stand check, the same final development package was confirmed installed on `wlanpi-da2`; its SHA256 matched. The active project remained `stage1-test` (id 2), image 6, `wlanpi-os-26.10-DeadEye-full.img.gz`, with verification, OS check and re-provision enabled. Serial, CID and `/dev/mmcblk0` were confirmed in the normal web dialog before the single wipe command over the restricted provisioner key.

Times below are UTC on 18 October (19 October locally):

- Wipe acknowledged at `23:10:39`; operation `a40ba1d66ca8262b7123ca7aaf89dac0` advanced to `booted` without a retry.
- New run started at `23:11:12`; writing started at `23:11:15`, verification at `23:13:41`.
- SHA256 passed; the module entered its installed OS at `23:16:21`.
- OS check passed at `23:17:47`, systemd `running`, no failed units. Total cycle: **395 seconds (6:35)**. The first Identify heartbeat was recorded at the same second as the OS-check report.
- Card at 1440 and 390 px: Identify/Stop switched the real helper between `identify` and `ready`; dashboard Identify and automatic expiry passed as well. There were no browser errors, and the OS-check timestamp stayed unchanged.
- Actual ACT/PWR sysfs readings during Identify: 100 samples, all with opposite states, 14 transitions in approximately 2 seconds. This kernel reports brightness as `0`/`255`; the verification normalises non-zero to on.
- The real `cmprovision-firstboot.service` had main PID `1815`. Exactly one helper ran; after expiry it was PID `7203`, `ready`, in `/run/cmprovision-leds.bI5d8u/`. The directory belonged to root, mode `0700`, containing only `ledpattern`.
- Before the ordinary reboot, `/usr/local/lib/cmprovision-firstboot`, the unit file and enablement symlink were already absent. The test package marker existed. Log SHA256: `07d01e6aebba1546b410fd524883ea3bb04996f06d8467cf9caeda8a524fa884`.
- Ordinary reboot passed: boot ID changed from `086c404e-3b44-4a52-b40c-2f3ed1b20361` to `20b2561d-c569-4541-921f-69066cdeb09b`. The runtime directory and LED helper were gone; unit, enablement link and firstboot inputs remained absent. The installed test package marker remained present.
- Log SHA256, provisioning start and OS-check time stayed unchanged after reboot; OS-check success records stayed at 4. No packages/scripts/report were repeated, and no new provisioning run started.
- The last heartbeat stayed at `23:19:52 UTC` and expired normally. The actual card at 1440 and 390 px then hid Identify and displayed the missing-heartbeat explanation. The two other sensors retained their previous run and OS-check timestamps.
- Final server check: `2.6.4~dev1` installed, `dpkg --audit` empty, nginx/PHP-FPM/DHCP/both queues active. Temporary browser sessions and SSH-operation directories were cleaned up.

## Release acceptance

- [x] Complete a fresh re-provision cycle with the final development package, then check Identify/Stop after the OS-check report.
- [x] Confirm on the sensor that the one-shot unit/scripts/packages are gone, and a subsequent normal reboot cannot repeat them or retain the temporary Identify loop.

The preliminary transient test loop was removed by the full re-provision cycle. The sensor is now running its installed OS after the ordinary reboot, with no temporary Identify loop. Other sensors were not modified. Stable 2.6.4 has not been released by this verification.
