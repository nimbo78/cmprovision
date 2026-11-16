> **Language / Язык:** [English](rescue-wifi.md) | [Русский](ru/rescue-wifi.md)

# Rescue Wi-Fi access

An optional protected hotspot gives engineers access when saved management Wi-Fi networks cannot be reached. It is disabled by default. The normal management radio keeps trying saved networks while a separate radio provides the AP.

## The Wi-Fi and Rescue AP cycle

![Automatic cycle: management available, 10-minute search, active AP, recovery with management and AP together, then standby.](diagrams/rescue-wifi-lifecycle.svg)

> [!NOTE]
> **AP STANDBY** means automatic rescue is enabled but the AP is not broadcasting. **AP RECOVERY** means management has returned while the AP is still running. Seeing a normal Wi-Fi connection and AP RECOVERY together is expected: they use separate radios.

## Enable it before losing management access

1. Open **Settings → Network → Rescue Wi-Fi access point**.
2. Enable automatic rescue Wi-Fi. The default search timeout is **10 minutes**; allowed range is 1–30 minutes.
3. Leave radio selection automatic for a built-in AP radio and a separate external client radio, or select their permanent MAC addresses under **Radio and network options**. They must use independent PHYs. A healthy client connection is never disconnected to start the AP.
4. Leave the private `/24` subnet automatic, or choose a nonoverlapping private `/24`. Existing routes and saved static networks are checked. Channels 1, 6 and 11 are available in 2.4 GHz; the system regulatory domain is retained.
5. Optionally select wired/VLAN management profiles. An active usable address on any selected profile also prevents fallback. The flashing interface never counts as management.
6. Save and confirm your web account password. Open **Connection details**, confirm again if requested, and save the generated SSID and password. **Show** and **Copy** are explicit actions; routine status and display snapshots contain no password.

> [!IMPORTANT]
> Provisioning DHCP must be bound to the flashing interface first. If the card reports a missing binding, configure it in Network settings during an idle maintenance window. Starting the rescue AP never restarts flashing DHCP or changes stand Ethernet/VLAN settings.

## What happens automatically

![AP shutdown timing: 60 seconds of stable management, then 60 seconds without clients. Connected clients keep the AP running. The 60-second QR card has its own timer.](diagrams/rescue-wifi-recovery.svg)

1. Management returns: keep the AP running and wait for **60 seconds of stable connectivity**.
2. After that, wait until **no clients are connected**, then count **another 60 seconds**.
3. Stop the AP and return to **AP STANDBY**. With no clients throughout, this takes about **2 minutes**, plus polling and radio transition time.

> [!IMPORTANT]
> The two intervals run **one after the other**. A new client resets the client-free interval. Losing management again resets recovery while keeping the AP available. If the client count is unknown, the AP stays on.

This is the **automatic** cycle. **Start now** requests at least 15 minutes of AP availability. Management means a usable address on Wi-Fi or a selected wired/VLAN profile; internet access is not required. The flashing interface does not count.

<details>
<summary>All automatic rules</summary>

| Situation | Result |
|---|---|
| Saved management networks disappear | Continue trying normal client connections; if the cold spare is enabled, also try it after 30 seconds. This is part of the 10-minute window, not extra time. |
| Management remains unavailable for 10 minutes | Start the protected AP on the idle selected radio. |
| Engineer joins | Open the IP shown in Settings, HDMI or FPMS; normal web/SSH authentication still applies. |
| Management returns | Wait for 60 seconds of stability, then keep the AP while any client is associated. |
| No clients remain | Wait a further 60 seconds, then stop the AP and return the radio to client use. |
| A new route overlaps the AP subnet | Stop the owned AP; select another subnet before restarting. |
| NetworkManager status or client count is unavailable | Preserve existing AP access and report the uncertainty. |

</details>

## How access reaches sensors

```mermaid
flowchart LR
    E[Engineer laptop / phone] -->|WPA2 Wi-Fi| A[Rescue AP]
    A --> W[cmprovision web / SSH]
    A --> N[Station routing and NAT]
    N --> S[Ethernet sensors]
    N --> V[Management VLANs]
    N --> I[Internet when upstream is available]
```

The AP is routed, with NetworkManager providing DHCP, DNS and NAT. Engineers can reach attached sensor addresses and routed VLAN networks without adding return routes to sensors. Internet access follows the station's available upstream routes. Layer-2 discovery broadcasts and network boot are not relayed through the AP. IPv6 is disabled on the AP. Other station firewall rules still apply.

## Manual controls and passwords

- **Start now** requests at least 15 minutes of availability; it still respects radio safety checks.
- **Stop for 15 minutes** pauses automatic AP startup. A separate warning confirms the disconnection of an active AP.
- **Resume automatic policy** cancels a pause.
- Saving SSID, subnet or password while the AP is active stages changes for its next start. **Restart with saved settings** requires a separate disconnection confirmation.
- Disabling an active AP also requires disconnection confirmation. **Back** cancels the pending action.

The generated AP password is unique to this station. It differs from web and SSH credentials. A replacement must contain 12–63 printable ASCII characters.

## Status and recovery

When a rescue AP starts, an enabled HDMI display automatically shows its Wi-Fi QR and connection details for **60 seconds from activation**. No FPMS is required. Polling, renderer restarts or reconnecting HDMI do not restart that minute. A new AP activation gets a new minute; a stopped AP or disabled HDMI closes the card. FPMS OK can still open a fresh manual card, and KEY2 dismisses both its card and the automatic HDMI card.

Settings shows the state, countdown, active SSID, gateway, client count and staged changes. The uplink summary lists rescue access separately from internet connectivity. HDMI and both FPMS layouts show the same AP state and connection address. In FPMS Lite, open **Station** and press **OK** on an active AP to show a Wi-Fi connection QR on FPMS and HDMI for **60 seconds**. Another OK switches FPMS between QR and SSID/password; up/down scrolls long credentials, and **KEY2** closes both displays immediately. Scanning connects to the running AP, not staged settings. Normal status pages and shared snapshots do not contain the password.

For an activation failure, check the card's explanation, interface binding, selected radio, conflicting routes and the service journal:

```sh
sudo journalctl -u cmprovision-rescue-wifi.service -n 80 --no-pager
sudo /usr/lib/cmprovision/rescue-wifi status
```

An encrypted full backup includes the AP configuration and its NetworkManager profile as **network settings**. Migration preserves the destination station's network by default. Explicit network restoration may require selecting replacement radios on different hardware; a missing source MAC prevents activation rather than selecting an arbitrary card. See [full backups](backups.md).

## Updating the figures

The SVGs use the same warm palette as the LED guide. Their generator checks timing and client-retention behavior against the actual policy. Run from the repository root after changing that policy:

```sh
python docs/diagrams/build_rescue_wifi.py
python docs/diagrams/build_rescue_wifi.py --check
```

Open either figure on its own to zoom in. The steps above remain readable text on narrow screens.
