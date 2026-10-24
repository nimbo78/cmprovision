# FPMS builds for cmprovision

This development branch uses WLAN-Pi/wlanpi-fpms at
`8beeba40a0f9d4f8045ac556a5b0780a1ce9028e` plus `portable-cmprovision.patch`.
The patch contains the portable GPIO discovery, unchanged ST7735 driving logic,
shared cmprovision panel, full-menu adapter, Lite entry point, tests and packaging.
Upstream's BSD-3-Clause license and authorship are retained, including in the Lite package.

Build on a separate Debian 13 machine:

```sh
# git, dpkg-dev and coreutils; does not need ARM hardware to build this pure-Python package
sh packaging/fpms/build.sh lite /tmp/fpms-packages

# ARM64 builder with upstream debian/control Build-Depends and devscripts installed
sh packaging/fpms/build.sh wlanpi /tmp/fpms-packages
```

`fpms-lite` includes only the display driver, GPIO discovery, read-only panel and
navigation. It depends on Debian's Python, Pillow, libgpiod and spidev packages.
It does not include WLAN Pi network tools, Profiler, Bluetooth or their Python dependencies.
It conflicts with `wlanpi-fpms` to prevent competing services and file ownership.

The WLAN Pi build remains the normal `wlanpi-fpms` package, with a distinct
`+cmprovision1` version. Its existing menu and dependencies remain available.
cmprovision itself never edits files under `/opt/wlanpi-fpms`.

Both variants install `/usr/share/fpms/cmprovision.json` (protocol 1). Their display
process writes a heartbeat to `/run/fpms/cmprovision.json`, including whether an SPI
device for the display exists (the display itself is write-only and cannot be read back). The
cmprovision watcher starts a data consumer only when enabled and that heartbeat is current. Old
FPMS packages are not assumed compatible. Raspberry Pi OS needs `dtparam=spi=on` for the Lite package.

## Validation status

The Lite package was installed in clean Debian 13 ARM64 userspace on the test CM4;
the panel ran for three minutes on its actual Waveshare screen and released the
hardware automatically. This userspace used the host WLAN Pi kernel. It is not
acceptance of a cold-booted Raspberry Pi OS Lite image. Button navigation for this
new menu still needs a human check (the earlier driver probe covered all eight buttons).

The full-menu adapter has automated enable/disable/startup/navigation checks and
imports successfully in the existing WLAN Pi FPMS Python environment. The complete
custom WLAN Pi package and its upgrade path still need ARM64 build and hardware acceptance.

The recipes do not yet build complete OS images. Those and VLAN installation profiles
are subsequent work; this change is the shared display/menu foundation.

For a source-only check, run `prepare.sh` with a new directory, then run
`python3 -m pytest tests/test_cmprovision.py tests/test_portable_display.py` there.
Pillow >=10.1 and pytest are required. `tools/preview-cmprovision.py OUTPUT` renders
all pages with labelled DEMO data. The root-only hardware runner in the patched source
is for a disposable lab rootfs, never an active station.
