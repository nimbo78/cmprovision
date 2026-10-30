# FPMS front panel

**Development preview.** This feature needs the cmprovision changes in this branch
and a compatible FPMS build. It is not part of the existing public release.

FPMS shows a compact read-only view on a Waveshare 1.44-inch 128×128 display.
The first hardware target is CM4. A display is optional: web provisioning works
without it, and HDMI and FPMS can be used at the same time.

## Choose the installation profile

- **WLAN Pi OS:** a compatible `wlanpi-fpms` package adds a cmprovision entry to the
  existing menu. The existing FPMS process retains ownership of the screen and buttons.
- **Raspberry Pi OS Lite Trixie arm64:** `fpms-lite` supplies the compact station menu
  and hardware driver, without WLAN Pi applications or service dependencies.

The package recipes and current acceptance status are in
[packaging/fpms](../packaging/fpms/README.md). Full ready-to-flash images are still in development.

## Enable the panel

1. Install the matching FPMS package and cmprovision package on the station.
2. Open **Settings → Provisioning → FPMS display**.
3. Check **Enable cmprovision in FPMS**, then **Save FPMS settings**.
4. Optionally check **Open cmprovision when FPMS starts**. On WLAN Pi it takes effect
   at the next FPMS startup; saving it does not interrupt someone using another page.
5. Check the displayed status. `running` means the FPMS display process is alive, an SPI device
   for the display exists and the shared snapshot is fresh. The panel itself is write-only: nothing can
   read back whether a screen is really connected, so `running` with a dark screen means a wiring or
   power fault. `not installed`, `incompatible`, `no hardware` (no SPI device) and `waiting for FPMS`
   distinguish missing support from a missing or stopped display process.

The default is off. Turning it off removes the section from the WLAN Pi menu;
normal WLAN Pi functions continue to work. On Lite the panel says the integration is off.
The integration settings are included in normal cmprovision backups (a `--data-only` restore does not bring them back:
enable the panel again). A restore stops both display consumers before replacing the database, then starts their watchers
again. `apt remove` keeps `/etc/default/cmprovision-fpms.json`, so a WLAN Pi menu entry that was enabled stays and says
`Waiting for data`; `apt purge` removes the file.

## Raspberry Pi OS Lite: turn SPI on first

Raspberry Pi OS ships with SPI off. Add `dtparam=spi=on` to `/boot/firmware/config.txt` and reboot, or the
service stays idle and the status says `no hardware`. While `/dev/spidev0.0` does not exist the service
touches no GPIO line at all. Once it exists, the service takes the display lines and pulls up the eight button
lines (GPIO 5, 6, 13, 16, 19, 20, 21, 26): do not install it on a board where those pins serve something else.

## Keeping the patched FPMS on WLAN Pi OS

The package of the WLAN Pi profile is the upstream `wlanpi-fpms` with the version suffix `+cmprovision1`. The next
upstream release in the WLAN Pi apt repository is newer than it, and `apt upgrade` would replace it with a stock
FPMS: the cmprovision entry disappears and the status says `not installed`. Hold the package until a build of the new
upstream version with the patch is ready: `sudo apt-mark hold wlanpi-fpms`, and `sudo apt-mark unhold wlanpi-fpms` to release it.

## What the panel trusts

The snapshot is written by the web user, and FPMS runs as root. The panel opens the snapshot without following a link
and without waiting on a pipe, takes only a regular file of root or of the web user, bounds its size, and treats any
value it cannot read as missing: a bad snapshot shows `Waiting for data` or `Invalid timestamp` and never stops the menu
(a failure inside the cmprovision page returns to the stock menu). The root watcher of cmprovision does not open the
snapshot at all: it judges the data by the age of the file.

## Look

**Settings → Provisioning → FPMS display** has two choices that reach the panel with its data (no restart, no
root helper):

- **Theme.** *New* (the default) is drawn for this 1.44" screen, whose backlight washes out the dark: bitmap fonts one
  to one with its pixels, saturated colours, whole tiles of colour for what needs a look. *Classic* is the first look
  of the panel, kept for whom it suits.
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
| Ports | The switch of the selected port as on its front, the selected port below: its module, step and percent, the bar with speed and ETA while it runs, or what went wrong in up to two lines; what else is on a port (this server: `cm`; another cmprovision station: `cm` in an outline, told by the address it gives over LLDP/CDP — the stand's provisioner at 172.20.0.1, a second station at .2–.10; a device by what it says of itself; its link speed and PoE). A station finds its own port by the MAC of its interface on the stand network, at .1 or not |
| Devices | The modules on the stand in port order: state, or percent and bar while running, `SLOW +m:ss` when past its batch |
| Errors | Failed and silent modules first, with a short cause from the server (`Download cut (curl 18)`, `EEPROM: no SPI chip`, `No report for 6 min`); then USB observations and devices on ports that are not modules, greyed. The red count in the header counts modules only |
| Station | The provisioner and its version; how it reaches the management network: the Wi-Fi network, signal in dBm and quality in colour (as the web UI rates it), rate, band, generation, address, or `Wi-Fi not connected`, the built-in spare in use, a wired uplink, `No uplink`; then its health, problems first: the stand link and any other link (a USB Ethernet), services, switches (`silent N min`), free space |

A device card (the joystick centre on Ports, Devices or Errors) has a band in the colour of its state with the port
and the state, the name, the step with its bar, and below, scrolling, what went wrong and what to do, the exit codes,
the diagnostics (a healthy report has no title), MAC, serial and switch.

| Control | On a page | In a card |
| --- | --- | --- |
| Left / right | Previous / next page | Left: back to the list |
| Up / down | Select a row or a port; scroll Station | Scroll the text |
| Joystick centre | Open the card (on Overview with failures: Errors) | Back to the list |
| Button 1 | Overview | Overview |
| Button 2 | Leave: the first press asks (`Press 2 again to leave cmprovision`, 3 seconds), the second goes to the WLAN Pi menu; on Lite it is Overview | Back to the list |
| Button 3 | Nothing | Nothing |

Outside the cmprovision page the buttons are the stock WLAN Pi ones: button 1 cycles the shortcuts (Reachability,
LLDP, IP), button 2 rotates the display, button 3 jumps to Reboot/Shutdown, where the joystick centre confirms. That is
why button 3 does nothing inside the panel: a press of it that left the panel would leave the next press of the centre
— "open the card" — to confirm a reboot. A press of button 2 within two seconds after leaving is swallowed, so a hurried
third press does not turn the display over.

The controls cannot erase storage, start provisioning, change a project or switch off PoE.
Inside a device card, the identity stays pinned: if it disconnects, the display says
it left the stand instead of silently showing another device.

Lite blanks after 60 seconds of inactivity; a button wakes it without performing an
action. Its service can use `--sleep 0` to stay on or `--rotate 180` for a reversed
installation (buttons 1 and 3 swap with the screen; button 2 stays in the middle). WLAN Pi retains its own sleep and
orientation settings.

## Data freshness and resource use

FPMS reads a local JSON snapshot; it has no access to cmprovision's database or secrets.
It shares the existing HDMI producer, including the switch polling cache. Closing HDMI
does not stop data needed by FPMS, and collection stops when the last consumer stops.

Fresh data has no word. From 10 seconds the age is shown in yellow — on Overview as `Updated 12 s ago` in place of
the project line, elsewhere as `12 s` in the header (on a card, in its band) — and from a minute in minutes: the values
are the last saved observation, not proof that a flash is still running. Use the web UI
for the full diagnostic report. An absent or invalid snapshot gives a waiting/error
message, without freezing the menu.

On a 128×128 panel some list labels are shortened (a cut is shown with `...`); a cause from the server never is, and
the selected device's card wraps everything. The station page is a summary; network configuration remains
in the web interface or installer. The Wi-Fi network's name is shown; NetworkManager profile names and keys never
reach the panel.
