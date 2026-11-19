# FPMS builds for cmprovision

The supported `fpms-lite` profile uses WLAN-Pi/wlanpi-fpms at
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
process writes a heartbeat to `/run/fpms/cmprovision.json`. Current Lite identifies a supported
ST7735-compatible controller before initializing the display/buttons and adds its profile and ID.
Missing SPI, busy control pins or no supported reply leave the service idle; failed identification
is retried at most every 30 seconds. Older heartbeats without identity keep their previous semantics. The
cmprovision watcher starts a data consumer only when enabled and that heartbeat is current. Old
FPMS packages are not assumed compatible. The Waveshare profile needs `dtparam=spi=on`.

Identification uses SPI_3WIRE half-duplex readback on the existing GPIO10 data wire. RESET stays an
input with a weak pull-up; backlight and buttons are taken only after a positive response. An active
DPI profile is left alone. `raspi-utils-core` supplies read-only `pinctrl get` for kernels that report
unknown GPIO bias; missing or ambiguous configuration is not guessed. This selects the supported Waveshare 1.44-inch layout; a controller ID does
not uniquely identify panel dimensions.

The rectangular HyperPixel 4.0 Touch profile checks the Raspberry Pi DPI overlay, the bound Goodix GT911
input device and the active 480×800 output before publishing its identity. The native landscape menu uses the station’s existing Nunito and JetBrains Mono fonts. Its optional KMS Python binding
comes from `python3-kms++`; the FPMS station image installs it. Only the dedicated DPI overlay is used.
See [installation and touch controls](../../docs/fpms-display.md#hyperpixel-40-touch).

## Validation status

The Lite image passed a cold PoE boot on the CM4 station wlanpi-d62. Photos confirmed
Overview, Ports, Devices, Errors and Station on its Waveshare display. The operator
confirmed physical buttons, Appearance and Light/Dark/Auto selection, reboot and
shutdown on 14 November 2026. On 15 November the operator also confirmed Classic,
correct Auto appearance at the appropriate times, and phone connection using the
FPMS QR. The exact instant of the automatic day/night change was not watched.

On 15 November, a separate cold-start experiment cut Gi0/7 PoE for 15 seconds and read ID
`7c89f0` in 84 consistent requests before FPMS started. RESET input pull-up was sufficient; no LCD
initialization or reset pulse was sent. This qualifies readback on that fixture, not arbitrary panels.

On 16 November, the packaged Lite code passed physical headless startup on `wlanpi-da2`: no GPIO
requests with SPI disabled, and three rejected ID probes over 66 seconds with temporary SPI enabled.
In the SPI-enabled run, all 22 heartbeats stayed `hardware=false`; GPIO event monitoring confirmed no button/backlight
requests. SPI/GPIO and configuration were restored, provisioning/HDMI PIDs stayed unchanged.

For 2.30.3, the supported profile is Raspberry Pi OS Lite Trixie arm64 on CM4 with
the Waveshare 1.44-inch ST7735 display, including New/Classic and Light/Dark/Auto.

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
Phone scanning and connection from the physical FPMS display were confirmed by
the operator on 15 November 2026. Phone scanning from HDMI remains separately unconfirmed.

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
