# HDMI stand display

Connect a monitor or TV to the **provisioner** to follow the bench without a browser. The optional terminal display
shows the active project, connected modules, switch ports, progress, failures and saved diagnostic snapshots. It runs
on a Linux virtual console; it does not need a graphical desktop, Chromium or an internet connection.

This is separate from the target module's [tty2 troubleshooting console](local-console.md).

## Enable it

1. Connect the provisioner's HDMI output to the monitor and select its HDMI input. Full HD is the usual target;
   higher resolutions also work. Connect a keyboard to the provisioner if local navigation is needed.
2. Open **Settings → HDMI display** in the web UI.
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
panels and multiple switches rotate through pages, without dropping ports. `UNR` means **Unrecognized**; it does not
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
screen is intended for a trusted support area: it shows device names, serial numbers, project names and failure hints.

## Services and troubleshooting

`cmprovision-wallboard` is a small root watcher of connector state and the fixed settings file. It starts/stops
`cmprovision-wallboard-display`, whose renderer and persistent `wallboard:snapshot --watch` reader run as `www-data`.
The reader updates every two seconds and shares the web UI's SNMP cache. No monitor in Auto means no renderer,
application reader or extra switch polling. The watcher itself remains to detect the next connection.

Settings are stored in `/etc/default/cmprovision-wallboard.json` and included in a normal provisioner backup. The
screen uses tty8, with a readable Terminus font when available; other virtual consoles retain their fonts. On stopping,
the previous console is restored if tty8 is still active. Switching away manually is respected.

```sh
systemctl status cmprovision-wallboard cmprovision-wallboard-display
journalctl -u cmprovision-wallboard -u cmprovision-wallboard-display -n 50 --no-pager
cat /sys/class/drm/card*-HDMI-A-*/status
sudo -u www-data /var/lib/cmprovision/artisan wallboard:snapshot
```

An unexpectedly blank screen: check HDMI detection, the selected TV input, and Auto/Off first. If needed, select On
temporarily and use Alt+F8. A missing font or too small terminal is reported on the console; a higher resolution or
smaller console font leaves more rows for detail. Full diagnostic reports and recovery actions remain in the web UI.

The display unit is deliberately not enabled/started directly at boot: only the watcher starts it. When removing or
downgrading the package, both units stop; restore stops the reader before replacing the SQLite database. No reboot is
required for enabling, changing or disabling the display.

Implementation references: [systemd terminal service settings](https://www.freedesktop.org/software/systemd/man/latest/systemd.exec.html),
[Python curses](https://docs.python.org/3/library/curses.html), [Linux DRM interface](https://docs.kernel.org/gpu/drm-uapi.html).
Palette programming: [Linux console controls](https://man7.org/linux/man-pages/man4/console_codes.4.html).
