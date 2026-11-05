# Hybrid VLAN laboratory

`hybrid-vlan.sh` exercises a real NetworkManager, dnsmasq and Ethernet frames in a private Linux
network, mount and PID namespace. It requires root, `unshare`, `ip`, `busctl`, `dbus-daemon`,
NetworkManager, `nmcli`, dnsmasq and Python 3. Run it only on a disposable Linux test host:

```sh
sudo sh tests/integration/hybrid-vlan.sh
```

The test creates a veth pair, a native management address and tagged networks. It checks DHCP
offers and TFTP transfers, NetworkManager checkpoints, profile validation and conflicts. The
managed-profile tests also exercise a real DHCP lease, including rejection of overlapping leases
and routes, IPv4/IPv6 policy tables and rules, route attributes, and rollback of policy rules that
would divert provisioning traffic. Set `CMP_LAB_STAND_ONLY=1` for a move of the provisioning address between native and
tagged interfaces, including cancellation and preservation of a disabled secondary service.
It also verifies DHCP/TFTP on the tagged stand while the native management address is edited and restored.
`CMP_LAB_STAND_REBOOT_ONLY=1` simulates a power loss partway through saving that move: NetworkManager
is stopped, runtime state is removed, and recovery must restore the saved profiles and DHCP service.
`CMP_LAB_REBOOT_ONLY=1` similarly tests recovery of a general network profile. The namespace uses
a scheduler test double and no udev daemon; it removes its own virtual device if needed after a crash.

`CMP_LAB_ROLE_ONLY=1` tests primary/secondary/disabled roles and initial setup on a fresh VLAN,
including a real ARP address conflict, DHCP/TFTP isolation, cancellation without a prior DHCPv4 lease,
partial profile/service persistence and recovery after restarting NetworkManager with empty runtime
state. Service operations are simulated in this namespace; a separate test below uses real systemd.

On a disposable systemd test host, `network-watchdog-systemd.py` separately checks a uniquely
named transient timer, its cancellation and retry after failure. The branch packages the profile
controller and watchdog, but no package has been deployed or physically power-cycle tested.

`sudo python3 tests/integration/network-boot-order-systemd.py tools/stand-network-binding.py`
checks boot recovery with real systemd ordering. It creates two uniquely named harmless temporary units
and verifies that the recovery unit completes before its dependent service starts. It never restarts
the real cmprovision services. Boot recovery queues DHCP/TFTP activation to avoid waiting on its own
`Before=` dependency; ordinary watchdog recovery still waits for the service result.

`sudo python3 tests/integration/stand-role-services-systemd.py tools/stand-role.py`
checks role transitions, masking, boot enablement and restoration using uniquely named harmless
temporary systemd units. It includes masked units with existing boot links and removes its test units
and links on exit. It never changes the real cmprovision services.

This laboratory cannot replace hardware acceptance: it does not test a real switch, actual hardware
power loss, first boot on a fresh image, or the installed services on a provisioner.

## Station image setup

Run these checks in a disposable Linux environment with the PHP test dependencies installed:

```sh
sudo python3 tests/integration/station-setup-ssh.py
python3 tests/integration/station-image-setup.py image/setup/cmprovision-image-setup.py
```

The first starts a temporary loopback OpenSSH server, checking host-key pinning, board identity,
private stdin delivery and error redaction. It uses temporary keys and leaves real accounts unchanged.
The second checks configuration files, interrupted setup, retries and parsing Wi-Fi/Ethernet profiles
with real `nmcli --offline`. `CMP_LAB_NMCLI` can select a private NetworkManager binary.

`station-image-rootfs.py` additionally checks OS account tools inside a **disposable copy** of the
actual arm64 image rootfs. It requires QEMU binfmt support and a private mount/PID namespace:

```sh
lab=$(mktemp -d /root/cmp-station-rootfs-XXXXXX)
cp -a --reflink=auto /path/to/pristine/image/rootfs "$lab/rootfs"
sudo unshare --mount --pid --fork python3 tests/integration/station-image-rootfs.py \
  "$lab/rootfs" image/setup/cmprovision-image-setup.py
```

Use a fresh copy with `CMP_STATION_KEYS_ONLY=1` to test key-only access and its sudo policy.
The test refuses an ordinary rootfs path and never changes the source image. QEMU user-mode
does not validate setuid elevation, real Wi-Fi association or physical boot. SSH login, sudo,
network recovery and cold startup must still be accepted on hardware.
