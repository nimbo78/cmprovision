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

`fpms-lite` includes only the display driver, GPIO discovery, read-only panel and
navigation. It depends on Debian's Python, Pillow, libgpiod and spidev packages.
It does not include WLAN Pi network tools, Profiler, Bluetooth or their Python dependencies.
It conflicts with `wlanpi-fpms` to prevent competing services and file ownership.

The `fpms-lite` package installs `/usr/share/fpms/cmprovision.json` (protocol 1). Its display
process writes a heartbeat to `/run/fpms/cmprovision.json`, including whether an SPI
device for the display exists (the display itself is write-only and cannot be read back). The
cmprovision watcher starts a data consumer only when enabled and that heartbeat is current. Old
FPMS packages are not assumed compatible. Raspberry Pi OS needs `dtparam=spi=on` for the Lite package.

## Validation status

The Lite image passed a cold PoE boot on the CM4 station wlanpi-d62. Photos confirmed
Overview, Ports, Devices, Errors and Station on its Waveshare display. KEY2 was
checked by the operator. Remaining physical controls, Classic and Dark-mode
appearance still need acceptance. FPMS remains preview.

A full FPMS package for WLAN Pi OS is no longer planned. The existing adapter is retained for compatibility,
but its build, upgrade and hardware acceptance are outside the supported scope.

The separate image builder includes this package optionally. Network profiles and
station roles are configured in the web UI. See
[deployment options](../../docs/deployment-options.md).

For a source-only check, run `prepare.sh` with a new directory, then run
`python3 -m pytest tests/test_cmprovision.py tests/test_cmprovision_fonts.py tests/test_cmprovision_v2.py tests/test_portable_display.py`
there. Pillow >=10.1 and pytest are required. `tools/preview-cmprovision.py OUTPUT` renders
every page and a device card in each look (new with either Ports map, classic) with labelled DEMO data, or with
`--snapshot FILE` the snapshot of a station. The root-only hardware runner in the patched source
is for a disposable lab rootfs, never an active station.

The panel draws with bitmap fonts in `fpms/fonts/cmprovision` (Helvetica Bold 8/10/18 of the Adobe 100 dpi
X11 fonts, Spleen 6x12, misc-fixed 5x7, Silkscreen 8), made from their upstream files by
`tools/cmprovision-fonts.py`; their licences are beside them in `LICENSES/` and in the Lite package under
`/usr/share/doc/fpms-lite/fonts/`.
