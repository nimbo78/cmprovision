# FPMS builds for cmprovision

The FPMS preview uses WLAN-Pi/wlanpi-fpms at
`8beeba40a0f9d4f8045ac556a5b0780a1ce9028e` plus `portable-cmprovision.patch`.
The patch contains the portable GPIO discovery, unchanged ST7735 driving logic,
shared cmprovision panel, full-menu adapter, Lite entry point, tests and packaging.
Upstream's BSD-3-Clause license and authorship are retained, including in the Lite package.

Build on a separate Debian 13 machine:

```sh
# git, dpkg-dev and coreutils; does not need ARM hardware to build this pure-Python package
sh packaging/fpms/build.sh lite /tmp/fpms-packages

```

`fpms-lite` includes the display driver, GPIO discovery, station panel, navigation and
explicitly confirmed power controls. It depends on Debian's Python, Pillow, qrcode, libgpiod and spidev packages.
It does not include WLAN Pi network tools, Profiler, Bluetooth or their Python dependencies.
It conflicts with `wlanpi-fpms` to prevent competing services and file ownership.

The `fpms-lite` package installs `/usr/share/fpms/cmprovision.json` (protocol 1). Its display
process writes a heartbeat to `/run/fpms/cmprovision.json`, including whether an SPI
device for the display exists (the display itself is write-only and cannot be read back). The
cmprovision watcher starts a data consumer only when enabled and that heartbeat is current. Old
FPMS packages are not assumed compatible. Raspberry Pi OS needs `dtparam=spi=on` for the Lite package.

## Validation status

The Lite image passed a cold PoE boot on the CM4 station wlanpi-d62. Photos confirmed
Overview, Ports, Devices, Errors and Station on its Waveshare display. The operator
confirmed physical buttons, Appearance and Light/Dark/Auto selection, reboot and
shutdown on 14 November 2026. Classic and day/night Auto transitions still need
acceptance; physical phone scanning of the rescue QR is tracked below. FPMS remains preview.

The ready image 2.30.3~dev6 passed writing, SHA256 and its first-boot OS check on CM4
on 14 November 2026. Update recovery initialized without manual repair. After restoring
station data, networking and SPI, a clean shutdown and five-second PoE power cycle
returned a healthy system with FPMS reporting `running`. This does not add new physical
screen, theme or button observations; Raspberry Pi Imager customization remains untested.

A full FPMS package for WLAN Pi OS is no longer planned. The existing adapter is retained for compatibility,
but its build, upgrade and hardware acceptance are outside the supported scope.

KEY3 opens the Lite station menu with power actions and **Appearance**. The latter
reads and saves Light/Dark/Auto through the same bounded local bridge, under
`www-data`; root and the display never open SQLite. The joystick centre applies
the highlighted color mode, and KEY2 returns to the parent menu. A checked circle
marks the saved mode. The setting is shared with the web FPMS form and applies to
both layouts, independently of HDMI. A confirmed choice remains visible until a
fresh snapshot arrives; later web changes can supersede it.

For power actions, KEY1 confirms and KEY2 cancels; the joystick centre only selects.
The package's fixed root bridge starts a bounded `www-data` Artisan command through systemd,
using the same live confirmation service as the web UI. Root never reads the application database,
and the display sandbox remains unchanged. The updated `cmprovision4` package is required;
read-only previews and the retained WLAN Pi OS adapter do not supply a power callback.
The operator confirmed physical button checks and real reboot and shutdown cycles on 14 November 2026.

On Station, OK on an active rescue AP opens a Wi-Fi QR; another OK shows its SSID/password.
KEY2 closes the card. A private root-owned runtime file shares the running credentials
with the HDMI display user for 60 seconds, after which both displays return to normal.
The existing service sandbox is unchanged; no credentials enter the public snapshot.
Physical phone scanning from both displays still needs acceptance.

HDMI also opens its own 60-second card at AP activation, without requiring FPMS.
Closing the FPMS card publishes a root-owned timestamp-only marker so HDMI dismisses
that automatic card too; the marker contains no connection credentials.

The separate image builder includes this package optionally. Network profiles and
station roles are configured in the web UI. See
[deployment options](../../docs/deployment-options.md).

For a source-only check, run `prepare.sh` with a new directory, then run
`python3 -m pytest tests/test_cmprovision.py tests/test_cmprovision_fonts.py tests/test_cmprovision_v2.py tests/test_portable_display.py tests/test_cmprovision_power.py`
there. Pillow >=10.1 and pytest are required. `tools/preview-cmprovision.py OUTPUT` renders
every page and a device card in each look (new with either Ports map, classic) with labelled DEMO data, or with
`--snapshot FILE` the snapshot of a station. The root-only hardware runner in the patched source
is for a disposable lab rootfs, never an active station.

The panel draws with bitmap fonts in `fpms/fonts/cmprovision` (Helvetica Bold 8/10/18 of the Adobe 100 dpi
X11 fonts, Spleen 6x12, misc-fixed 5x7, Silkscreen 8), made from their upstream files by
`tools/cmprovision-fonts.py`; their licences are beside them in `LICENSES/` and in the Lite package under
`/usr/share/doc/fpms-lite/fonts/`.
