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

## Pages and buttons

| Page | What it shows |
| --- | --- |
| Overview | Active project and counts of running, completed, failed and other devices |
| Ports | A map: the front panel of one switch as small cubes, eight columns to a screen (`1/3` when the switch has more), laid out as on the switch; the captions under the map name the selected port and its module, phase and progress |
| Devices | Modules currently on the stand |
| Errors | Failed, silent and unrecognized devices |
| Station | Host/version, management address, provisioning interface and free storage |

On the Ports map a filled cube is a module (green finished, blue running, red failed, amber silent), a hollow cube is a free port, an outlined one is another device or a booting board, and the cube marked `cm` is the port of the provisioner itself. The frame around a cube is the selected port; up/down move it through all the ports of all switches (the map changes to the switch of the selected port), the joystick centre opens the card of its module. A switch that does not answer is shown with `No response`.

| Control | Action |
| --- | --- |
| Left / right | Previous / next page |
| Up / down | Select a row; scroll text in details |
| Joystick centre | Open details; close details when already inside |
| Button 1 | Overview |
| Button 2 | Ports |
| Button 3 | Back: closes the details; with none open, returns to the WLAN Pi menu (Overview on Lite) |

Outside the cmprovision page the buttons are the stock WLAN Pi ones: button 1 cycles the shortcuts (Reachability, LLDP, IP),
button 2 rotates the display, button 3 jumps to the Reboot/Shutdown shortcut. So the press of button 3 that comes within two seconds
after leaving the cmprovision page is swallowed: a double press must not reach the Reboot shortcut.

The controls cannot erase storage, start provisioning, change a project or switch off PoE.
Inside a device card, the identity stays pinned: if it disconnects, the display says
it left the stand instead of silently showing another device.

The panel uses the web UI's Warm / Dark colors. Long detail text scrolls with up/down.
Lite blanks after 60 seconds of inactivity; a button wakes it without performing an
action. Its service can use `--sleep 0` to stay on or `--rotate 180` for a reversed
installation. WLAN Pi retains its own sleep and orientation settings.

## Data freshness and resource use

FPMS reads a local JSON snapshot; it has no access to cmprovision's database or secrets.
It shares the existing HDMI producer, including the switch polling cache. Closing HDMI
does not stop data needed by FPMS, and collection stops when the last consumer stops.

`Updated …s ago` shows the age. After 20 seconds it becomes `Stale data`: the values
are the last saved observation, not proof that a flash is still running. Use the web UI
for the full diagnostic report. An absent or invalid snapshot gives a waiting/error
message, without freezing the menu.

On a 128×128 panel some list labels are shortened; the selected device's details
provide wrapped text. The station page is a summary; network configuration remains
in the web interface or installer.
