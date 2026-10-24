# HDMI stand display

Connect a monitor or TV to the **provisioner** to follow the bench without a browser. The optional terminal display
shows the active project, connected modules, switch ports, progress, failures and saved diagnostic snapshots. It runs
on a Linux virtual console; it does not need a graphical desktop, Chromium or an internet connection.

This is separate from the target module's [tty2 troubleshooting console](local-console.md).

## Enable it

1. Connect the provisioner's HDMI output to the monitor and select its HDMI input. Full HD is the usual target;
   higher resolutions also work. Connect a keyboard to the provisioner if local navigation is needed.
2. Open **Settings → Provisioning → HDMI display** in the web UI.
3. Select **Auto**, choose a screen and colors, and save. The display starts within a few seconds.
4. Use **Alt+F1** for the normal system console and **Alt+F8** to return to the stand display. SSH remains available.

After saving Auto once, no keyboard or login is needed: the enabled watcher starts at boot, detects HDMI and switches
to the display automatically. Connecting HDMI later also starts it. The saved mode survives package updates and reboots.

The default is **Off**. **Auto** starts the renderer and its application data reader only while a DRM HDMI connector
reports `connected`. Disconnecting the cable stops both. **On** renders regardless of HDMI detection, useful for
testing a connector whose detection is unreliable. Neither mode changes a module, project, PoE state or provisioning
job. Display settings take effect without restarting the provisioning services.

A TV in standby, a KVM or an HDMI adapter can keep reporting `connected`. Detection describes the connector, not
whether someone is watching or whether the TV has selected this input. Use Off when this distinction matters.

## Screens and navigation

The console automatically sizes its font from the current visible HDMI resolution. With the bundled Terminus fonts,
1920×1080 uses 14×28-pixel cells, 3440×1440 uses 20×36 cells, and 3840×2160 uses 28×56 cells. This keeps about 38–40 rows
of readable text instead of shrinking the entire interface at high resolution. The watcher adjusts tty8 when the HDMI
mode changes; it does not switch away from a console you selected manually. No provisioning service is restarted.
Older kernels that reject tall fonts fall back to a smaller supported font. Selection is recorded in
`/run/cmprovision-wallboard/layout.json`; Settings also shows the detected resolution.

The layout uses the available width with small margins. Ultrawide screens fit three cards per row; ordinary Full HD
and 4K fit two. A compact switch front panel stays together when it fits beside the device card. The header uses a
small `cm` tile with the name of the stand on the tile's middle row, and a plain-text project name, rather than a large
word drawn from hash characters.

A module is named by its host name, or by its MAC address while it has none, in the table and as the title of the device
card. The card's second line is the MAC address under a host name, or `S/N` and the serial number under a MAC address,
so nothing is shown twice; with a host name the serial number follows on the fourth line.

The table under the front panel has three columns across the whole width: **Port / device**, **Phase** and
**Progress / result**, which says the percent, the speed and the time left of a module that writes (`63% / 25.0 MiB/s / ETA
1:00`, the speed as on the device card; a module that has not reported one yet shows the percent and the time). The first column is as wide as the longest port and device name needs (at most about half the
screen), the result takes the rest. A text that still does not fit is not cut: it rests at its start, runs through its
column one step at a time (about three columns a second), rests at its end and starts over; a `>` at the end of the
column says there is more. The same goes for the device name and port on the device card and for the failure line at
the bottom. The screen is redrawn faster only while something runs; with every text fitting nothing moves.

After the modules the table lists what a switch shows on a port where there is a device that is not a module and is not booting,
so the table tells what was plugged in: the phase says `UNR` (an unrecognized device) or `LNK` (a link up with nothing sent from
it), the name is what the device calls itself over LLDP or CDP with its system, address and abilities in the result column, and for
a device that says nothing it is the maker of its MAC address (the Raspberry Pi blocks are built in, the `ieee-data` package adds
the others) with the MAC itself. Texts that come from a device are cleaned before they reach the screen. The `flow` cards and the
`engineer` view say the speed of a write as the table does: `63% / 25.0 MiB/s / ETA 1:00`. A module that runs well past what the
finished ones of its batch took (2.19.0, [how it is decided](operations.md#a-module-that-takes-longer-than-the-rest)) gets
`/ SLOW +3:20` after its time left, and its row is drawn in the warning colour.

The four display themes match the web UI: **Warm / Dark**, **Warm / Light**, **Neutral / Dark**, and **Neutral / Light**.
The renderer reads the same `resources/css/app.css` tokens for the page, text, muted text, accent and status colors.
Warm dark uses the web's `#ff8a3d` orange; neutral dark uses `#818cf8` indigo. Alpha text colors are composited over the
page background. On the Linux console, the eight palette entries are redefined to these RGB values without changing
other virtual terminals; stopping the display resets tty8's palette. A terminal without programmable colors falls back
to basic ANSI colors. The HDMI theme is a shared setting; browser appearance is selected independently per browser.
The earlier Phosphor/Monochrome choices migrate to Warm dark/Neutral dark when read, preserving Auto and other settings.

- **Stand console:** switch front panels, selected module and a table of modules on the stand.
- **All device cards:** several cards at once, with additional pages for larger batches.
- **Large device card and diagnostics:** a carrier-board ASCII diagram, reported provisioning steps, saved diagnostic
  evidence and advice. The display does not request a new snapshot or execute recovery actions.
- **Automatic:** the stand console for a working/ready module, the large card for a failed or silent one.

Cards change every 12 seconds by default (configurable from 5 to 60). Left/right arrows select a card, **Space** pauses
rotation and **V** cycles the screens locally. Failures remain visible in the footer while other cards are shown.
The local screen choice lasts until the renderer stops; Settings defines its next start.

Verified switch models use the same physical port banks as the [Ports page](switch-front-panels.md), including the
2960CX's odd/even rows and separate RJ45/SFP uplinks. Other models use the application's automatic layout. Large
panels and multiple switches rotate through pages, without dropping ports. A free port is an empty frame with its number,
so the panel keeps its shape and nothing on it flickers; the port where the provisioner itself is connected says `cm`, and
the other uplinks (a link to another switch, a port with many devices) say `UP`. `UNR` means **Unrecognized**; it does not
mean a module has been registered. See [discovery troubleshooting](unrecognized-devices.md).

## What the numbers mean

ON STAND counts modules currently found on the switch map. Finished records from disconnected devices do not inflate
the count. Without a configured managed switch, recent module polls and active recent runs are shown with an unknown
port. A new USB-only board can appear as **Unregistered module**, separate from provisioning success/failure totals.

Progress, rate and ETA use the same calculations as the web UI. Silent modules are marked **NO REPORT**. Diagnostic
temperature, power and other facts are **saved snapshots**: their source and age are always shown. They are not live
hardware sensors. If application data stops updating for more than ten seconds, **DATA STALE** replaces the normal
footer. A switch that stops answering keeps its last known ports with an explicit warning.

No image files, project scripts, account passwords, SNMP credentials or run tokens are sent to the terminal. The local
screen is intended for a trusted support area: it shows device names, MAC addresses, serial numbers, project names and failure hints.

## Services and troubleshooting

Three units work together. `cmprovision-wallboard` is a small root watcher of connector state and the fixed settings
file; it starts and stops `cmprovision-wallboard-display`, and only it does (the display and the data units are not
enabled at boot). The display's renderer runs as `cmprovision-wallboard`, a system user of its own made by the
package: it draws on tty8 and reads one JSON file, nothing else. That file is kept current by
`cmprovision-wallboard-data`, which runs `wallboard:snapshot --watch --output=/run/cmprovision-wallboard-data/snapshot.json`
as the web user, without a terminal; it starts and stops with the display.

The data unit updates the file every two seconds and shares the web UI's SNMP cache: it takes a stored switch snapshot
up to 15 seconds old and polls a switch itself only when that has run out, so with a monitor attached each switch is asked
about every 15 seconds at most. No monitor in Auto means no renderer, no data unit and no extra switch polling; the
watcher itself remains to detect the next connection.

The watcher counts a connector change only after two equal readings in a row, so a TV in standby or a loose cable does
not start and stop the display. A new connection (or boot) takes the console for tty8; a restart after a crash or a
package upgrade does not, and a display unit that keeps failing is retried every ten seconds three times, then once a
minute.

Settings are stored in `/etc/default/cmprovision-wallboard.json` and included in a normal provisioner backup. The
screen uses tty8, with a readable Terminus font when available; other virtual consoles retain their fonts. On stopping,
the previous console is restored if tty8 is still active. Switching away manually is respected.

```sh
systemctl status cmprovision-wallboard cmprovision-wallboard-display cmprovision-wallboard-data
journalctl -u cmprovision-wallboard -u cmprovision-wallboard-display -u cmprovision-wallboard-data -n 50 --no-pager
cat /sys/class/drm/card*-HDMI-A-*/status
sudo -u www-data /var/lib/cmprovision/artisan wallboard:snapshot
```

An unexpectedly blank screen: check HDMI detection, the selected TV input, and Auto/Off first. If needed, select On
temporarily and use Alt+F8. A missing font or too small terminal is reported on the console; a higher resolution or
smaller console font leaves more rows for detail. Full diagnostic reports and recovery actions remain in the web UI.

The display unit is deliberately not enabled/started directly at boot: only the watcher starts it. When removing or
downgrading the package, all three units stop; restore stops the data unit before replacing the SQLite database. No reboot is
required for enabling, changing or disabling the display.

Implementation references: [systemd terminal service settings](https://www.freedesktop.org/software/systemd/man/latest/systemd.exec.html),
[Python curses](https://docs.python.org/3/library/curses.html), [Linux DRM interface](https://docs.kernel.org/gpu/drm-uapi.html).
Palette programming: [Linux console controls](https://man7.org/linux/man-pages/man4/console_codes.4.html).
Font scaling: [setfont](https://kbd-project.org/manpages/man8/setfont.8.html),
[framebuffer geometry](https://docs.kernel.org/fb/api.html).
