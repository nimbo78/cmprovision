#!/bin/sh
# A real NetworkManager checkpoint in a private Linux network/mount/PID namespace.
# Run only on a disposable Linux test host as root. Never run in the host namespace.
set -eu
if [ "${1:-}" != --inner ]; then
    [ "$(id -u)" = 0 ] || { echo 'root required' >&2; exit 1; }
    exec unshare --net --mount --pid --fork --mount-proc sh "$0" --inner
fi
NM=${CMP_LAB_NM:-/usr/sbin/NetworkManager}
NMCLI=${CMP_LAB_NMCLI:-/usr/bin/nmcli}
# /etc, /run and /var/lib are private mounts; no host connection files or services are touched.
mount --make-rprivate /
mount -t sysfs sysfs /sys
mount -t tmpfs -o mode=755 tmpfs /run
mount -t tmpfs -o mode=755 tmpfs /etc
mount -t tmpfs -o mode=755 tmpfs /var/lib
printf 'root:x:0:0:root:/root:/bin/sh\n' >/etc/passwd
printf 'root:x:0:\n' >/etc/group
mkdir -p /run/NetworkManager /run/udev/data /etc/NetworkManager/system-connections /var/lib/NetworkManager
printf '[main]\nplugins=keyfile\ndhcp=internal\nno-auto-default=*\n' >/etc/NetworkManager/NetworkManager.conf
export XDG_RUNTIME_DIR=/run DBUS_SYSTEM_BUS_ADDRESS=unix:path=/run/test-bus
# A private bus and no host NetworkManager: the daemon sees only this namespace's veth pair.
dbus-daemon --session --address="$DBUS_SYSTEM_BUS_ADDRESS" --fork --print-pid >/dev/null
ip link add cmp-test0 type veth peer name cmp-peer0
ip link set cmp-peer0 up
ip link set cmp-test0 up
ip link add link cmp-peer0 name cmp-client42 type vlan id 42
ip link set cmp-client42 up
# A private udev database marks only our virtual links initialized; no host udev trigger is sent.
for dev in cmp-test0 cmp-peer0; do
    i=$(cat "/sys/class/net/$dev/ifindex")
    printf 'I:1000000\nE:ID_NET_DRIVER=veth\n' >"/run/udev/data/n$i"
done
"$NM" --debug --config=/etc/NetworkManager/NetworkManager.conf --log-level=WARN >/run/nm.log 2>&1 &
nmpid=$!
trap 'kill "$nmpid" 2>/dev/null || true' EXIT
n=0
until "$NMCLI" -t general status >/dev/null 2>&1; do
    n=$((n + 1)); if [ "$n" -gt 30 ]; then cat /run/nm.log >&2; exit 1; fi; sleep .2
done
# Start before the stand address exists as can happen on boot.
mkdir /run/tftp
printf 'cmprovision-vlan-probe\n' >/run/tftp/probe.txt
start_dnsmasq() {
    dnsmasq --keep-in-foreground --port=0 --no-hosts --no-resolv --user=root --group=root \
        --interface="$1" --bind-dynamic --enable-tftp --tftp-root=/run/tftp \
        --dhcp-range=172.20.0.10,172.20.0.20,255.255.0.0,5m \
        --dhcp-leasefile=/run/lab.leases --no-ping >/run/dnsmasq.log 2>&1 &
    dnsmasq_pid=$!
    sleep 1
    if ! kill -0 "$dnsmasq_pid" 2>/dev/null; then cat /run/dnsmasq.log >&2; exit 1; fi
}
stop_dnsmasq() { kill "$dnsmasq_pid"; wait "$dnsmasq_pid" || true; }
# At boot the configured VLAN may not exist until NetworkManager activates it. Verify that a
# dnsmasq already running at that point starts serving it without a service restart.
start_dnsmasq cmp-test0.42
trap 'kill "$dnsmasq_pid" "$nmpid" 2>/dev/null || true' EXIT
"$NMCLI" connection add type ethernet ifname cmp-test0 con-name cmp-native \
    ipv4.method manual ipv4.addresses '192.0.2.10/24' ipv4.never-default yes ipv6.method disabled >/dev/null
"$NMCLI" --wait 10 connection up cmp-native >/dev/null
"$NMCLI" connection add type vlan ifname cmp-test0.42 dev cmp-test0 id 42 con-name cmp-boot-stand \
    ipv4.method manual ipv4.addresses 172.20.0.1/16 ipv4.never-default yes ipv6.method disabled >/dev/null
"$NMCLI" --wait 10 connection up cmp-boot-stand >/dev/null
python3 "$(dirname "$0")/dhcp-probe.py" cmp-client42 offer
python3 "$(dirname "$0")/tftp-probe.py" cmp-client42 data
"$NMCLI" connection delete cmp-boot-stand >/dev/null
stop_dnsmasq
"$NMCLI" connection modify cmp-native +ipv4.addresses 172.20.0.1/16
"$NMCLI" device reapply cmp-test0 >/dev/null
start_dnsmasq cmp-test0
# VLAN 99 is tagged management/service; it coexists with untagged and the VLAN being tested.
"$NMCLI" connection add type vlan ifname cmp-test0.99 dev cmp-test0 id 99 con-name cmp-service \
    ipv4.method manual ipv4.addresses 198.51.100.10/24 ipv4.never-default yes ipv6.method disabled >/dev/null
"$NMCLI" --wait 10 connection up cmp-service >/dev/null
if [ "${CMP_LAB_STAND_ONLY:-0}" = 1 ] || [ "${CMP_LAB_STAND_REBOOT_ONLY:-0}" = 1 ]; then
    export CMP_LAB_DNS_PID="$dnsmasq_pid"
    export CMP_LAB_NM_PID="$nmpid"
    stand_test=stand-network-nm.py
    if [ "${CMP_LAB_STAND_REBOOT_ONLY:-0}" = 1 ]; then stand_test=stand-reboot-nm.py; fi
    python3 "$(dirname "$0")/$stand_test" "${CMP_LAB_PROFILE:-$(dirname "$0")/../../tools/network-profile.py}" \
        "${CMP_LAB_TRANSACTION:-$(dirname "$0")/../../tools/network-transaction.py}" \
        "${CMP_LAB_STORE:-$(dirname "$0")/../../tools/network-transaction-store.py}" \
        "${CMP_LAB_BACKEND:-$(dirname "$0")/../../tools/network-transaction-backend.py}" \
        "${CMP_LAB_STAND_BACKEND:-$(dirname "$0")/../../tools/stand-network-backend.py}" \
        "${CMP_LAB_BINDING:-$(dirname "$0")/../../tools/stand-network-binding.py}" \
        "${CMP_LAB_DNSMASQ_START:-$(dirname "$0")/../../tools/dnsmasq-start}" \
        "${CMP_LAB_ROUTER:-$(dirname "$0")/../../tools/network-transaction-router.py}"
    exit 0
fi
python3 "$(dirname "$0")/vlan-managed-test.py" "${CMP_LAB_HELPER:-$(dirname "$0")/../../tools/vlan-networks}"
python3 "$(dirname "$0")/network-profile-nm.py" "${CMP_LAB_PROFILE:-$(dirname "$0")/../../tools/network-profile.py}"
python3 "$(dirname "$0")/network-transaction-nm.py" "${CMP_LAB_PROFILE:-$(dirname "$0")/../../tools/network-profile.py}" \
    "${CMP_LAB_TRANSACTION:-$(dirname "$0")/../../tools/network-transaction.py}" \
    "${CMP_LAB_STORE:-$(dirname "$0")/../../tools/network-transaction-store.py}"
python3 "$(dirname "$0")/network-backend-nm.py" "${CMP_LAB_PROFILE:-$(dirname "$0")/../../tools/network-profile.py}" \
    "${CMP_LAB_TRANSACTION:-$(dirname "$0")/../../tools/network-transaction.py}" \
    "${CMP_LAB_STORE:-$(dirname "$0")/../../tools/network-transaction-store.py}" \
    "${CMP_LAB_BACKEND:-$(dirname "$0")/../../tools/network-transaction-backend.py}"
python3 "$(dirname "$0")/network-cli-nm.py" "${CMP_LAB_CLI:-$(dirname "$0")/../../tools/network-transaction}"
python3 "$(dirname "$0")/network-dhcp-nm.py" "${CMP_LAB_PROFILE:-$(dirname "$0")/../../tools/network-profile.py}" \
    "${CMP_LAB_TRANSACTION:-$(dirname "$0")/../../tools/network-transaction.py}" \
    "${CMP_LAB_STORE:-$(dirname "$0")/../../tools/network-transaction-store.py}" \
    "${CMP_LAB_BACKEND:-$(dirname "$0")/../../tools/network-transaction-backend.py}"
if [ "${CMP_LAB_REBOOT_ONLY:-0}" = 1 ]; then
    export CMP_LAB_NM_PID="$nmpid"
    python3 "$(dirname "$0")/network-reboot-nm.py" "${CMP_LAB_PROFILE:-$(dirname "$0")/../../tools/network-profile.py}" \
        "${CMP_LAB_TRANSACTION:-$(dirname "$0")/../../tools/network-transaction.py}" \
        "${CMP_LAB_STORE:-$(dirname "$0")/../../tools/network-transaction-store.py}" \
        "${CMP_LAB_BACKEND:-$(dirname "$0")/../../tools/network-transaction-backend.py}"
    exit 0
fi
if [ "${CMP_LAB_MANAGED_ONLY:-0}" = 1 ]; then exit 0; fi
# Exercise real DHCP packets on the native segment and tagged VLAN, not just socket bindings.
python3 "$(dirname "$0")/dhcp-probe.py" cmp-peer0 offer
python3 "$(dirname "$0")/dhcp-probe.py" cmp-client42 none
python3 "$(dirname "$0")/tftp-probe.py" cmp-peer0 data
python3 "$(dirname "$0")/tftp-probe.py" cmp-client42 none
if [ "${CMP_LAB_RESTART_ONLY:-0}" != 1 ]; then
busctl --address="$DBUS_SYSTEM_BUS_ADDRESS" call org.freedesktop.NetworkManager /org/freedesktop/NetworkManager \
    org.freedesktop.NetworkManager CheckpointCreate aouu 0 30 4 >/dev/null
# A concurrent application may create another profile while the checkpoint is pending.  Do not use
# DELETE_NEW_CONNECTIONS: it would remove that unrelated profile on rollback.
"$NMCLI" connection add type ethernet ifname cmp-peer0 con-name cmp-concurrent connection.autoconnect no >/dev/null
# Temporary changes do not leave the stand address missing after a power loss before confirmation.
"$NMCLI" connection modify --temporary cmp-native -ipv4.addresses 172.20.0.1/16
"$NMCLI" device reapply cmp-test0 >/dev/null
"$NMCLI" connection add save no type vlan ifname cmp-test0.42 dev cmp-test0 id 42 con-name cmp-stand \
    connection.autoconnect no ipv4.method manual ipv4.addresses 172.20.0.1/16 ipv4.never-default yes ipv6.method disabled >/dev/null
"$NMCLI" --wait 10 connection up cmp-stand >/dev/null
stop_dnsmasq
start_dnsmasq cmp-test0.42
ip -4 -o address show dev cmp-test0 | grep -Fq '192.0.2.10/24'
ip -4 -o address show dev cmp-test0.42 | grep -Fq '172.20.0.1/16'
ip -4 -o address show dev cmp-test0.99 | grep -Fq '198.51.100.10/24'
python3 "$(dirname "$0")/dhcp-probe.py" cmp-client42 offer
python3 "$(dirname "$0")/dhcp-probe.py" cmp-peer0 none
python3 "$(dirname "$0")/tftp-probe.py" cmp-client42 data
python3 "$(dirname "$0")/tftp-probe.py" cmp-peer0 none
# No confirmation: NetworkManager restores the original profile and disconnects the temporary device.
sleep 31
stop_dnsmasq
start_dnsmasq cmp-test0
ip -4 -o address show dev cmp-test0 | grep -Fq '192.0.2.10/24'
ip -4 -o address show dev cmp-test0 | grep -Fq '172.20.0.1/16'
ip -4 -o address show dev cmp-test0.99 | grep -Fq '198.51.100.10/24'
"$NMCLI" -t -f NAME connection show | grep -Fxq cmp-concurrent
if ip -4 -o address show dev cmp-test0.42 2>/dev/null | grep -Fq '172.20.0.1/16'; then
    echo 'the rolled-back VLAN still has the stand address' >&2; exit 1
fi
# The unsaved profile remains in NM memory until it is explicitly deleted or NM restarts; it must not autoconnect.
[ "$("$NMCLI" -g connection.autoconnect connection show cmp-stand)" = no ]
python3 "$(dirname "$0")/dhcp-probe.py" cmp-peer0 offer
python3 "$(dirname "$0")/dhcp-probe.py" cmp-client42 none
python3 "$(dirname "$0")/tftp-probe.py" cmp-peer0 data
python3 "$(dirname "$0")/tftp-probe.py" cmp-client42 none
"$NMCLI" connection delete cmp-stand >/dev/null
else
    "$NMCLI" connection add type ethernet ifname cmp-peer0 con-name cmp-concurrent connection.autoconnect no >/dev/null
fi

# Simulate NM being killed during an unconfirmed change. The original keyfile must still carry
# the address. Runtime connection files can survive a daemon restart, so explicitly clean up the
# transaction's own profile and reapply the saved native profile, as a recovery helper would.
busctl --address="$DBUS_SYSTEM_BUS_ADDRESS" call org.freedesktop.NetworkManager /org/freedesktop/NetworkManager \
    org.freedesktop.NetworkManager CheckpointCreate aouu 0 30 4 >/dev/null
"$NMCLI" connection modify --temporary cmp-native -ipv4.addresses 172.20.0.1/16
"$NMCLI" device reapply cmp-test0 >/dev/null
"$NMCLI" connection add save no type vlan ifname cmp-test0.42 dev cmp-test0 id 42 con-name cmp-stand \
    connection.autoconnect no ipv4.method manual ipv4.addresses 172.20.0.1/16 ipv4.never-default yes ipv6.method disabled >/dev/null
"$NMCLI" --wait 10 connection up cmp-stand >/dev/null
grep -Fq '172.20.0.1/16' /etc/NetworkManager/system-connections/cmp-native.nmconnection
kill -9 "$nmpid"
wait "$nmpid" 2>/dev/null || true
"$NM" --debug --config=/etc/NetworkManager/NetworkManager.conf --log-level=WARN >/run/nm-restart.log 2>&1 &
nmpid=$!
n=0
until "$NMCLI" -t general status >/dev/null 2>&1; do
    n=$((n + 1)); if [ "$n" -gt 50 ]; then cat /run/nm-restart.log >&2; exit 1; fi; sleep .2
done
"$NMCLI" connection delete cmp-stand >/dev/null
# The private lab has no udev daemon to initialize virtual devices after an NM restart; remove
# the known lab device if it is left unmanaged. A real helper must verify its parent and VLAN ID.
if ip link show dev cmp-test0.42 >/dev/null 2>&1; then ip link delete dev cmp-test0.42; fi
# NetworkManager keeps the in-memory override in /run even after loading the saved keyfile. A
# recovery transaction must explicitly put back the address it removed before reapplying.
"$NMCLI" connection modify --temporary cmp-native +ipv4.addresses 172.20.0.1/16
"$NMCLI" device reapply cmp-test0 >/dev/null
n=0
until ip -4 -o address show dev cmp-test0 | grep -Fq '172.20.0.1/16'; do
    n=$((n + 1)); if [ "$n" -gt 50 ]; then
        cat /run/nm-restart.log >&2
        "$NMCLI" device status >&2 || true
        "$NMCLI" connection show >&2 || true
        ip -4 address >&2
        exit 1
    fi; sleep .2
done
"$NMCLI" -t -f NAME connection show | grep -Fxq cmp-concurrent
if "$NMCLI" -t -f NAME connection show | grep -Fxq cmp-stand; then
    echo 'the transaction VLAN profile was not removed' >&2; exit 1
fi
ip -4 -o address show dev cmp-test0 | grep -Fq '192.0.2.10/24'
ip -4 -o address show dev cmp-test0.99 | grep -Fq '198.51.100.10/24'
python3 "$(dirname "$0")/dhcp-probe.py" cmp-peer0 offer
python3 "$(dirname "$0")/tftp-probe.py" cmp-peer0 data
if [ "${CMP_LAB_RESTART_ONLY:-0}" = 1 ]; then
    printf '%s\n' 'PASS: interrupted NetworkManager recovery'
else
    printf '%s\n' 'PASS: hybrid DHCP/TFTP isolation, timeout rollback and interrupted NetworkManager recovery'
fi
