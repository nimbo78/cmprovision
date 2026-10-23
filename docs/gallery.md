# Screenshot tour

[Back to README](../README.md) · [Quick start](../README.md#quick-start) · [Operation guide](operations.md)

Screenshots of the current interface, captured from a disposable local copy on 17 October 2026. **All device records, projects, network readings and switch responses are demo data.** Speeds, power readings and completion times are illustrative, not hardware measurements. The images and firmware listed in the demo are placeholders.

Most views use **Warm · Light · Compact** at a 1440-pixel browser width. Each screenshot links to its original PNG; dialogs and status panels are captured separately so their controls remain readable.

| Main tabs | More views |
| --- | --- |
| [Dashboard](#dashboard) · [CMs](#cms) · [Ports](#ports) | [Module detail](#module-detail) · [Connection status](#connection-status) |
| [Images](#images) · [Projects](#projects) · [Scripts](#scripts) | [Appearance](#appearance) · [Density](#density) |
| [Labels](#labels) · [Firmware](#firmware) · [Settings](#settings) | [Mobile navigation](#mobile-navigation) · [Account](#account) |

## Dashboard

Follow the active batch without opening individual records. Modules show their current phase, progress, write or verification rate, remaining time and errors. The demo includes writing, verification, first boot, completed runs and a missing-storage failure. Eligible modules offer **Restart** and **Identify** actions.

<a href="../assets/readme/screenshots/dashboard.png"><img src="../assets/readme/screenshots/dashboard.png" width="100%" alt="Dashboard with eight demo modules, batch totals, progress bars and recovery controls"></a>

## CMs

Search the module inventory, review each device's latest result and export records as CSV. Open a serial number for the device's detailed record.

<a href="../assets/readme/screenshots/cms.png"><img src="../assets/readme/screenshots/cms.png" width="100%" alt="CMs inventory showing serial numbers, model, board, project, provisioning dates and status"></a>

## Ports

See the stand as a switch port map. State filters highlight running, done, failed or free ports. Selecting a port reveals its module, failure advice, link and PoE information. This example selects the failed module on **sw01 GE0/0/6**.

<a href="../assets/readme/screenshots/ports.png"><img src="../assets/readme/screenshots/ports.png" width="100%" alt="Switch port map with eight modules and a selected failed port showing storage advice, Identify, Restart and PoE consumption"></a>

Port lookup needs SNMP read access. PoE restart needs separate write access and a supported switch; see [switch setup and safeguards](operations.md#switches-and-poe-restart).

## Images

Upload compressed OS images and review their checksums before assigning them to a project.

<a href="../assets/readme/screenshots/images.png"><img src="../assets/readme/screenshots/images.png" width="100%" alt="Images tab with two demo OS images, their checksums and upload controls"></a>

## Projects

Keep provisioning settings in named projects and choose which one is active for the stand. A project combines the image, destination storage, optional EEPROM settings, scripts, first-boot checks and label template.

<a href="../assets/readme/screenshots/projects.png"><img src="../assets/readme/screenshots/projects.png" width="100%" alt="Projects tab with an active factory project and a recovery project"></a>

<details>
<summary>Open the project editor</summary>
<p>The editor includes whole-image verification, split-image recovery, first-boot OS checks and EEPROM configuration.</p>
<a href="../assets/readme/screenshots/project-editor.png"><img src="../assets/readme/screenshots/project-editor.png" width="512" alt="Project editor with image, storage, verification, first-boot, firmware and script options"></a>
</details>

## Scripts

Manage scripts for the pre-install, post-install and first-boot stages. Upload Debian packages for installation during the first-boot OS check. The target network has no internet, so package dependencies must already be present or uploaded too.

<a href="../assets/readme/screenshots/scripts.png"><img src="../assets/readme/screenshots/scripts.png" width="100%" alt="Scripts tab with three lifecycle scripts and an uploaded demo Debian package"></a>

<details>
<summary>Open the script editor</summary>
<p>Edit the script's name, execution stage and shell commands.</p>
<a href="../assets/readme/screenshots/script-editor.png"><img src="../assets/readme/screenshots/script-editor.png" width="512" alt="Script editor showing a first-boot application service check"></a>
</details>

See [first-boot requirements](operations.md#first-boot-os-check) before enabling scripts and packages in a project.

## Labels

Create label templates for provisioned devices and attach a template to a project. Template fields can include the module's serial number and the hostname reported by its installed system.

<a href="../assets/readme/screenshots/labels.png"><img src="../assets/readme/screenshots/labels.png" width="100%" alt="Labels tab with a module serial and hostname template"></a>

<details>
<summary>Open the label editor</summary>
<p>Configure the printer connection and edit its label commands using the available device-data placeholders.</p>
<a href="../assets/readme/screenshots/label-editor.png"><img src="../assets/readme/screenshots/label-editor.png" width="512" alt="Label editor with printer FTP settings and a ZPL template containing serial and hostname placeholders"></a>
</details>

## Firmware

Review the available Raspberry Pi bootloader EEPROM releases by channel. Choose the firmware to apply in the project editor.

<a href="../assets/readme/screenshots/firmware.png"><img src="../assets/readme/screenshots/firmware.png" width="100%" alt="Firmware tab listing demo default and latest EEPROM channel entries"></a>

## Settings

Settings is split into four tabs by purpose: **Status** (the services of the server and their logs), **Network** (Wi-Fi networks, SNMP switches, DHCP assignments), **Provisioning** (utility OS, re-provisioning, the HDMI display) and **Notifications** (channels and batches). On the three tabs besides Status, a strip above the sections shows the state of the services and a menu with their logs. The demo runs without provisioning hardware, so DHCP and USB boot services are shown as stopped. This view was captured on 23 October 2026 from the development bench.

<a href="../assets/readme/screenshots/settings.png"><img src="../assets/readme/screenshots/settings.png" width="100%" alt="Settings page, Status tab, with the services of the server, running or stopped, a View log button for each and the utility OS in use"></a>

## Module detail

A module's record brings together its hardware, project and image, switch port, last-run phases, first-boot result, EEPROM before and after, and log history. Here the installed system has reported back successfully with its hostname.

<a href="../assets/readme/screenshots/module-detail.png"><img src="../assets/readme/screenshots/module-detail.png" width="100%" alt="Completed module record showing its hostname, successful OS check, hardware, step timings, unchanged EEPROM and history"></a>

## Connection status

Open or hover over the network indicator in the navigation bar to inspect the uplink. For Wi-Fi it shows the generation, network name, signal strength, negotiated link rate, band, channel, channel width, adapter and address. It also shows the built-in radio's cold-spare state and the separate Ethernet network serving the modules.

<a href="../assets/readme/screenshots/connection.png"><img src="../assets/readme/screenshots/connection.png" width="660" alt="Wi-Fi status panel showing Wi-Fi 6, signal and link details, a standby built-in radio and the eth0 module network"></a>

The Wi-Fi speed is the **link rate**, not measured internet throughput. See [cold-spare behavior](operations.md#built-in-wi-fi-as-a-cold-spare) for failover details.

## Re-provision an installed sensor

The module card checks access before offering the destructive action. These three views were captured on 18 October 2026 from the development implementation with simulated SSH responses, in Warm · Dark. No device was erased for the screenshots.

| Check access | Confirm identity, disk and project |
| --- | --- |
| <a href="../assets/readme/screenshots/reprovision-access.png"><img src="../assets/readme/screenshots/reprovision-access.png" width="440" alt="Re-provision dialog ready to check sensor access"></a> | <a href="../assets/readme/screenshots/reprovision-confirm.png"><img src="../assets/readme/screenshots/reprovision-confirm.png" width="440" alt="Re-provision dialog with a matched serial, eMMC disk, CID and project before the wipe confirmation"></a> |

<details>
<summary>A sensor that requires a new password</summary>
<a href="../assets/readme/screenshots/reprovision-password.png"><img src="../assets/readme/screenshots/reprovision-password.png" width="512" alt="Re-provision dialog asking for a new sensor password and its confirmation"></a>
</details>

See [supported layouts, key installation and recovery](operations.md#re-provision-an-installed-sensor).

Settings also has an ordered list of saved sensor accounts. The public factory profiles below are demonstration data; stored custom passwords are never displayed.

<a href="../assets/readme/screenshots/reprovision-accounts.png"><img src="../assets/readme/screenshots/reprovision-accounts.png" width="720" alt="Settings for saved re-provision accounts, with WLAN Pi enabled and legacy Raspberry Pi disabled"></a>

## Appearance

Open **Appearance** in the navigation bar. Choose one of two styles, **Warm** or **Neutral**, and a **Light**, **Dark** or **Auto** theme. The same dashboard is shown in all four palettes below. Choices are saved in the browser and synchronized between its open tabs.

| Warm · Light | Warm · Dark |
| --- | --- |
| <a href="../assets/readme/screenshots/warm-light.png"><img src="../assets/readme/screenshots/warm-light.png" width="440" alt="Dashboard in the Warm light palette"></a> | <a href="../assets/readme/screenshots/warm-dark.png"><img src="../assets/readme/screenshots/warm-dark.png" width="440" alt="Dashboard in the Warm dark palette"></a> |

| Neutral · Light | Neutral · Dark |
| --- | --- |
| <a href="../assets/readme/screenshots/neutral-light.png"><img src="../assets/readme/screenshots/neutral-light.png" width="440" alt="Dashboard in the Neutral light palette"></a> | <a href="../assets/readme/screenshots/neutral-dark.png"><img src="../assets/readme/screenshots/neutral-dark.png" width="440" alt="Dashboard in the Neutral dark palette"></a> |

**Auto** uses sunrise and sunset for the representative location associated with the server's time zone. If solar times are unavailable, it follows the operating system's light or dark preference. The panel explains which rule is active.

<a href="../assets/readme/screenshots/appearance.png"><img src="../assets/readme/screenshots/appearance.png" width="540" alt="Appearance panel with Auto selected and a sunrise-to-sunset schedule based on the server time zone"></a>

## Density

**Compact** fits more information on the screen. **Roomy** gives rows and controls more space. Density is independent of the selected style and theme.

| Compact | Roomy |
| --- | --- |
| <a href="../assets/readme/screenshots/density-compact.png"><img src="../assets/readme/screenshots/density-compact.png" width="440" alt="Dashboard with compact row spacing"></a> | <a href="../assets/readme/screenshots/density-roomy.png"><img src="../assets/readme/screenshots/density-roomy.png" width="440" alt="Dashboard with roomy row spacing"></a> |

## Mobile navigation

On a narrow screen, the menu keeps all nine main tabs, the account links and appearance controls within reach.

<a href="../assets/readme/screenshots/mobile-menu.png"><img src="../assets/readme/screenshots/mobile-menu.png" width="320" alt="Mobile navigation expanded with all main tabs, profile, API tokens and appearance controls"></a>

## Account

The account menu opens **Profile** and **API Tokens**. Manage account and session settings in Profile; create tokens for automation under API Tokens. See the [HTTP API guide](api.md) for requests.

<details>
<summary>Profile</summary>
<a href="../assets/readme/screenshots/profile.png"><img src="../assets/readme/screenshots/profile.png" width="100%" alt="Demo user's profile page with account information, password, two-factor authentication and browser session controls"></a>
</details>

<details>
<summary>API Tokens</summary>
<a href="../assets/readme/screenshots/api-tokens.png"><img src="../assets/readme/screenshots/api-tokens.png" width="100%" alt="API Tokens page with a token name field and permission selection"></a>
</details>

[Set up your first stand](../README.md#quick-start) · [Screenshot provenance](../assets/readme/README.md)
