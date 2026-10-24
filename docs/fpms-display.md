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
5. Check the displayed status. `running` means the hardware display process is alive
   and the shared snapshot is fresh. `not installed`, `incompatible`, `no hardware`
   and `waiting for FPMS` distinguish missing support from a missing or stopped display.

The default is off. Turning it off removes the section from the WLAN Pi menu;
normal WLAN Pi functions continue to work. On Lite the panel says the integration is off.
The integration settings are included in normal cmprovision backups. A restore stops
both display consumers before replacing the database, then starts their watchers again.

## Pages and buttons

| Page | What it shows |
| --- | --- |
| Overview | Active project and counts of running, completed, failed and other devices |
| Ports | Switch ports and their current states; select a module's port to see its details |
| Devices | Modules currently on the stand |
| Errors | Failed, silent and unrecognized devices |
| Station | Host/version, management address, provisioning interface and free storage |

| Control | Action |
| --- | --- |
| Left / right | Previous / next page |
| Up / down | Select a row; scroll text in details |
| Joystick centre | Open details; close details when already inside |
| Button 1 | Overview |
| Button 2 | Ports |
| Button 3 | Back; from a top-level page, return to the WLAN Pi menu (Overview on Lite) |

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
