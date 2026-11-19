> **Language / Язык:** [English](fpms-display.md) | [Русский](ru/fpms-display.md)

# FPMS front panel

**Supported profile for 2.30.3.** `fpms-lite` on Raspberry Pi OS Lite Trixie arm64,
CM4 and a Waveshare 1.44-inch ST7735 display. Both New and Classic layouts and
Light/Dark/Auto color modes are included. The integration has been available since 2.23.0.

Cold boot, the five New pages, physical buttons, Appearance, reboot and shutdown
were confirmed on hardware. On 15 November 2026 the operator confirmed Classic,
correct Auto appearance at the appropriate times, and phone connection using the
QR on the FPMS display. The exact instant of the automatic day/night change was
not observed continuously.

The ready image **2.30.3~dev6** passed CM4 hardware acceptance on 14 November 2026:
image writing and SHA256, the first-boot OS check, and a cold PoE boot after restoring
station data and networking. Update recovery started without manual repair; FPMS
reported `running` after SPI restoration. Raspberry Pi Imager customization remains untested.

FPMS shows provisioning and station data on the Waveshare 1.44-inch 128×128 panel or the rectangular HyperPixel 4.0 Touch. The controls below refer to the Waveshare.

## HyperPixel 4.0 Touch

1. Power down the CM4 station, disconnect PoE and USB power, and fit the display to the 40-pin header with pin 1 aligned.
2. On Raspberry Pi OS Lite Trixie, install the matching `fpms-lite` package and `python3-kms++`. Keep the existing full KMS overlay (`vc4-kms-v3d`). In the active CM4 or `[all]` section of `/boot/firmware/config.txt`, set the following lines once and remove conflicting SPI or I²C enable lines.

   ```ini
   dtparam=i2c_arm=off
   dtparam=spi=off
   dtoverlay=vc4-kms-dpi-hyperpixel4
   ```

3. Reboot and enable **Settings → System → FPMS display**. The detected panel appears as **HyperPixel 4.0 Touch · 800 × 480 · GT911**. Changing panel families requires selecting the appropriate boot configuration and rebooting. FPMS identifies the controller on the configured interface. The menu uses landscape orientation. For the opposite mounting, the existing `fpms.lite --rotate 180` option flips the picture and touch coordinates together.
4. Tap the tabs at the bottom to navigate. The first tap after the screen goes dark wakes it. The square and non-touch HyperPixel models use different hardware.

- **Overview** shows four batch counters and six devices at a time. Tap a counter to filter by status or a row to open a device. The arrows page through the stand. **Back** returns to the same list and page.
- **Ports** opens on the physical map. **Connected** lists names, Ethernet link and PoE separately, including a powered port with no carrier. Watts or `On` indicate delivered power, `Idle` means waiting for a device, `Off` means disabled and `?` means no reading. Tap a port and then its device name for diagnostics.
- Open **Devices** and tap a name for diagnostics. **Errors** brings failed, silent and unrecognized devices into one list.
- **Station** shows the management network, address, Wi-Fi RSSI and transmit link rate, or Ethernet speed. In **Station health**, each stopped service has a red badge with its name. Services disabled by configuration have neutral badges. Tap the strip for details, or tap the active rescue AP badge for its Wi-Fi QR.
- **System** groups appearance and screen timeout under **Display** and separates the station power controls.

HyperPixel uses a dedicated DPI overlay. If HDMI mirrors the same display pipeline or no overlay plane is free, FPMS waits. The station and device data come from the same snapshot as the HDMI wallboard. [Pimoroni display setup](https://github.com/pimoroni/hyperpixel4)

On `wlanpi-d62` the matched controller provided three physical calibration points. After a normal reboot on 19 November 2026, FPMS identified the GT911, opened the landscape menu and resumed receiving live station and switch data.

## Waveshare controls

The Station page in Classic and New also shows [rescue Wi-Fi](rescue-wifi.md): its search countdown or active SSID, gateway and associated-client count. In Lite, press **OK** while the AP is active to open its Wi-Fi QR code. Press **OK** again to see the SSID and password; up/down scrolls long credentials. **KEY2** closes the card. The same QR and connection details appear temporarily on HDMI. Both screens return to their normal view after **60 seconds**, or when the card is closed. A normal status page never shows the password.
The first hardware target is CM4. A display is optional: web provisioning works
without it, and HDMI and FPMS can be used at the same time.

## Choose the installation profile

HDMI also opens an automatic 60-second QR card when the rescue AP starts, including on stations without FPMS. FPMS retains explicit OK access; KEY2 dismisses both cards.

- **Raspberry Pi OS Lite Trixie arm64:** `fpms-lite` supplies the compact station menu
  and hardware driver, without WLAN Pi applications or service dependencies.

A complete FPMS profile for WLAN Pi OS is not planned.

The package recipes and current acceptance status are in
[packaging/fpms](../packaging/fpms/README.md). Version 2.24.0 also provides an experimental ready-to-flash Lite image.

## Change the color mode from the panel

In Lite, press **KEY3 → Appearance**, select **Light**, **Dark**, or **Auto** with
the joystick, then press its centre to apply. The filled circle marks the current
mode; highlighting another row does not save it. **KEY2** returns to the station
menu; press it again to close the menu.

The change applies immediately to either New or Classic and persists across restarts.
It uses the same FPMS setting as **Settings → System → FPMS display**; HDMI retains
its own color mode. Auto continues to follow the station's sunrise/sunset calculation.
If saving is not confirmed, reopen Appearance to read the saved mode. Restart and
shutdown still require their separate **KEY1** confirmation.

## Screen timeout

In **Settings → System → FPMS display → Screen timeout**, choose an idle interval and select **Save FPMS settings**. On HyperPixel, tap **System → Screen timeout** and the desired interval. On Waveshare, open **KEY3 → Screen timeout**, use the joystick to choose, and press its centre to save. **KEY2** goes back.

Both menus use the same station setting. The default is **1 minute**. Choose **15 seconds** through **1 hour**, or **Never** to keep the panel on. The first touch or button press wakes a blank screen. The first-administrator setup code keeps the panel awake while pairing.

## Enable the panel

1. Install the matching FPMS package and cmprovision package on the station.
2. Open **Settings → System → FPMS display**.
3. Check **Enable cmprovision in FPMS**, then **Save FPMS settings**.
4. Check the displayed status. With the current Lite package, `running` means the controller answered
   identification requests, FPMS is alive and the shared snapshot is fresh. Settings also shows the
   detected panel and controller ID. Older FPMS packages without identification still report
   process/SPI availability only. `not installed`, `incompatible`, `no hardware` (SPI unavailable or no
   supported controller response) and `waiting for FPMS` distinguish missing support from a stopped process.

The default is off. On Lite the panel says the integration is off until enabled.
The integration settings are included in normal cmprovision backups (a `--data-only` restore does not bring them back:
enable the panel again). A restore stops both display consumers before replacing the database, then starts their watchers
again. `apt remove` keeps `/etc/default/cmprovision-fpms.json`; `apt purge` removes the file.

## Enable SPI for the Waveshare panel

Raspberry Pi OS ships with SPI off. Add `dtparam=spi=on` to `/boot/firmware/config.txt` and reboot, or the
service stays idle and the status says `no hardware`. While `/dev/spidev0.0` does not exist the service
touches no GPIO line at all. With SPI available, it identifies the controller before taking the backlight
and eight button lines (GPIO 5, 6, 13, 16, 19, 20, 21, 26). The Lite package includes a dependency on
`raspi-utils-core`: when the kernel cannot report a control pin’s pull resistor, its read-only `pinctrl get`
command supplies the value needed for restoration. Do not install it on a board where those pins
serve something else.

### Automatic Waveshare identification

At each Lite service start, FPMS reads the ST7735-compatible controller ID over the existing SPI data
wire. It checks two matching samples and cross-checks individual ID bytes against the combined reply.
Only a supported response starts the normal display and button driver. With no response it stays idle,
keeps its heartbeat fresh, and retries at most once every 30 seconds. An active DPI configuration or
busy control pins are left alone. Each attempt restores the SPI mode and previous control-pin state;
configurations that cannot be safely restored are not probed. No button press or station reboot is needed for detection itself.

The CM4/Waveshare fixture on `wlanpi-d62` returned **7C89F0**, including before FPMS initialization
after a 15-second PoE power cut. The ID identifies the controller; it does not uniquely establish panel
dimensions or confirm that its backlight and pixels work. This selects the supported 1.44-inch wiring
and layout.

Headless startup was checked on `wlanpi-da2` using the packaged Lite code: with SPI disabled,
no GPIO requests occurred; with SPI temporarily enabled and no panel fitted, three probes over
66 seconds returned no ID. In that run, all 22 heartbeats reported `hardware=false`; buttons and backlight
were never requested. The original SPI/GPIO state was restored and provisioning/HDMI service
PIDs stayed unchanged.

Always remove all power before changing a GPIO-connected panel.

## What the panel trusts

The snapshot is written by the web user, and FPMS runs as root. The panel opens the snapshot without following a link
and without waiting on a pipe, takes only a regular file of root or of the web user, bounds its size, and treats any
value it cannot read as missing: a bad snapshot shows `Waiting for data` or `Invalid timestamp` and never stops the menu
(a failure inside the cmprovision page returns to the stock menu). The root watcher of cmprovision does not open the
snapshot at all: it judges the data by the age of the file.

## Look

Layout and color mode are selected in the web settings; the panel buttons do not change them.

**Settings → System → FPMS display** has two choices that reach the panel with its data (no restart, no
root helper):

- **Layout.** *New* (the default) is drawn for this 1.44" screen, whose backlight washes out the dark: bitmap fonts one
  to one with its pixels, saturated colours, whole tiles of colour for what needs a look. *Classic* is the first look
  of the panel, kept for whom it suits.
- **Color mode.** *Dark*, *Light*, or *Auto* applies to either layout. Auto uses the same sunrise/sunset calculation
  as the web interface at the reference location of the station's display time zone. Without coordinates or during
  polar day/night it falls back to Dark; the display has no browser system preference. Existing settings stay Dark.
- **Ports map** (New only). A switch of up to 8 columns is drawn whole with its port numbers either way. A wider one
  is drawn either as *Pages with numbers* (8 columns, 16 ports, to a screen; `2/3` and dots tell the page) or as
  *Whole switch* (every port at once in narrow cells; the number of the selected port is in the line below).

The fonts are bitmap fonts made for small screens: Helvetica Bold 8, 10 and 18 of the Adobe 100 dpi X11 fonts for
titles, lists and counts, Silkscreen 8 (Google, OFL) for what went wrong, Spleen 6x12 for MAC and addresses,
misc-fixed 5x7 for port numbers. Their licences are installed with them.

## Pages and buttons

| Page | What it shows |
| --- | --- |
| Overview | The project and its batch (`stage1-test 37 done 6:20`: done and ETA of the batch), the modules on the stand in four tiles (FAILED and SILENT filled in their colour when above zero, RUNNING, DONE) and the whole stand as small cells |
| Ports | The switch of the selected port as on its front, the selected port below: its module, step and percent, the bar with speed and ETA while it runs, or what went wrong in up to two lines; what else is on a port (this server: `cm`; another cmprovision station: `cm` in an outline, told by its LLDP description `cmprovision <version> station` — the stand's provisioner when its address is 172.20.0.1, a second station otherwise; a device by what it says of itself; its link speed and PoE). A station finds its own port by the MAC of its interface on the stand network, at .1 or not |
| Devices | The modules on the stand in port order: state, or percent and bar while running, `SLOW +m:ss` when past its batch |
| Errors | Failed and silent modules first, with a short cause from the server (`Download cut (curl 18)`, `EEPROM: no SPI chip`, `No report for 6 min`); then USB observations and devices on ports that are not modules, greyed. The red count in the header counts modules only |
| Station | The provisioner and its version; how it reaches the management network: the Wi-Fi network, signal in dBm and quality in colour (as the web UI rates it), rate, band, generation, address, or `Wi-Fi not connected`, the built-in spare in use, a wired uplink, `No uplink`; then its health, problems first: the stand link and any other link (a USB Ethernet), services, switches (`silent N min`), free space |

A sensor verified by the shared station key appears as `OURS` on Ports and in Devices, with the flashing station and marked boot in its card. It is not counted as a local run or listed as an error. Like the web page, recognition lasts only while the sensor emits its configured temporary mark.

A device card (the joystick centre on Ports, Devices or Errors) has a band in the colour of its state with the port
and the state, the name, the step with its bar, and below, scrolling, what went wrong and what to do, the exit codes,
the diagnostics (a healthy report has no title), MAC, serial and switch.

| Control | On a page | In a card |
| --- | --- | --- |
| Left / right | Previous / next page | Left: back to the list |
| Up / down | Select a row or a port; scroll Station | Scroll the text |
| Joystick centre | Open the card (on Overview with failures: Errors) | Back to the list |
| Button 1 | Overview | Overview |
| Button 2 | Overview | Back to the list |
| Button 3 | Station power menu (Lite only) | Station power menu (Lite only) |

The controls cannot erase storage, start provisioning, change a project or switch off PoE.
In Lite's power menu, up/down selects Cancel, Restart or Shut down; the centre opens confirmation.
KEY1 confirms, KEY2 cancels. Unfinished provisioning requires a separate KEY1 confirmation based on live
server data. Confirmations expire after two minutes; no action is taken from a display snapshot.
After shutdown, cycle PoE or external power to start the station again.
See [station power](operations.md#restart-and-shut-down-the-provisioner) for the complete flow.
Inside a device card, the identity stays pinned: if it disconnects, the display says
it left the stand instead of silently showing another device.

For a one-off run, `--sleep SECONDS` overrides the saved timeout (0 keeps the screen on).
Use `--rotate 180` for a reversed
installation (buttons 1 and 3 swap with the screen; button 2 stays in the middle).

## Data freshness and resource use

FPMS reads a local JSON snapshot; it has no access to cmprovision's database. The snapshot contains no passwords. Only an explicit OK on the active rescue AP asks the installed local helper for that AP's running credentials, including when different settings are staged. A separate root-owned runtime file, readable only by root and the HDMI display user, shares them for at most 60 seconds. The file is removed when the card closes; expired files are ignored.
It shares the existing HDMI producer, including the switch polling cache. Closing HDMI
does not stop data needed by FPMS, and collection stops when the last consumer stops.

Fresh data has no word. From 10 seconds the age is shown in yellow — on Overview as `Updated 12 s ago` in place of
the project line, elsewhere as `12 s` in the header (on a card, in its band) — and from a minute in minutes: the values
are the last saved observation, not proof that a flash is still running. Use the web UI
for the full diagnostic report. An absent or invalid snapshot gives a waiting/error
message, without freezing the menu.

On a 128×128 panel some list labels are shortened (a cut is shown with `...`); a cause from the server never is, and
the selected device's card wraps everything. The station page is a summary; network configuration remains
in the web interface or installer. The Wi-Fi network's name is shown; NetworkManager profile names and management Wi-Fi keys never
reach the panel. The explicitly opened rescue access card is the only password display.

## Disabled boot services

Station shows intentionally masked DHCP/TFTP and USB boot services as neutral `disabled`.
A failed boot service or stopped worker remains red. Settings uses the same classification.
HDMI currently shows provisioning and port states without a station-service list.
