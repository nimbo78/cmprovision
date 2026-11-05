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

On a disposable systemd test host, `network-watchdog-systemd.py` separately checks a uniquely
named transient timer, its cancellation and retry after failure. The branch packages the profile
controller and watchdog, but no package has been deployed or physically power-cycle tested.

This laboratory cannot replace hardware acceptance: it does not test a real switch, actual hardware
power loss, first boot on a fresh image, or the installed services on a provisioner.
