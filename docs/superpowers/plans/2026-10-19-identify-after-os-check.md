# Identify after the OS check — 2.6.4

## Behaviour and scope

After the first-boot report, keep asking the existing Identify endpoint every 5 seconds. Identify switches ACT/PWR to the existing fast pattern for 30 seconds; Stop, expiry or a failed request restores `ready` or `fail`. While identifying, ask every 2 seconds. The card and dashboard use their existing controls and heartbeat expiry (120 seconds).

This lasts until the next reboot. Do not install a permanent agent, rerun packages/scripts, or change SSH access. Previously provisioned systems need the new first-boot script: upgrading the server alone cannot change their running service.

## Implementation

- [x] Add a self-contained LED/polling tail to firstboot. Move only the checked helper to a private directory under `/run`, then remove the unit, enablement link and entire firstboot directory, including scripts and packages. Stop/reap the previous helper on each transition; clean runtime files and the helper on termination. Preserve kernel-trigger fallback when the helper is unavailable.
- [x] Bind new-system polls to the provisioning start time, so a previous run cannot revive a new run's bench status. Keep legacy utility-OS polls compatible. Use bounded HTTP requests and accept Identify only from a successful response.
- [x] Replace the card's claim that installed systems cannot Identify with current heartbeat status and a useful explanation for old scripts, disconnection or reboot.
- [x] Test the real shell: ready/fail → Identify → original result, repeated Identify, network errors, fallback LEDs, removal of sensitive files before polling and termination cleanup. Test matching/stale run polls, existing UI actions, expiry and old-system text.
- [x] Update operations/LED documentation and changelog as `2.6.4~dev1`. Run relevant tests, then the full existing suite; inspect desktop/mobile controls.
- [x] Build a development package and verify on the stand: button, helper process/mode, Stop, automatic expiry, original result, no repeated OS-check report/scripts. Record hardware evidence before calling the feature complete.

[Stand results and release acceptance](2026-10-19-identify-hardware-check.md): both the preliminary post-report loop check and the complete fresh re-provision/ordinary reboot cycle passed on `100000004acecfa4`. Full cycle: 6:35, SHA256 and OS check passed; Identify/Stop/expiry worked, and reboot did not repeat firstboot tasks. The development package is installed on `wlanpi-da2`; a stable release is the next step.

## Acceptance

Both successful and failed OS checks can Identify while still on the stand. A disappearing server never leaves the Identify pattern stuck. Scripts and packages are gone before the first heartbeat, only one LED helper runs, and reboot cannot repeat the OS check. Existing utility OS behaviour stays compatible. A stable release is a separate step after verification.
