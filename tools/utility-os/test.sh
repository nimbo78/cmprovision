#!/bin/sh
# Check the built utility OS (2.7.0, docs/utility-os.md), after tools/utility-os/build.sh with the same UOS_WORK:
#   kernel    every option the OS needs is built in (=y); modules stay on (no =m of the defconfig built in)
#   contents  every command of utility-os/legacy-commands.txt and pinctrl, no kernel module, no network login service
#   qemu      qemu-system-aarch64 -M virt boots the same kernel8.img and scriptexecute.img; the init gets its script from
#             smoke/server.py after a 502, and the script sends back the start parameters, the versions of the tools and
#             their messages (smoke/verify.py writes tests/fixtures/utility-os/messages.txt)
#   size      kernel8.img + scriptexecute.img at most 32 MiB
#   manifest  scriptexecute64/MANIFEST lists exactly its files
# One ok/FAIL line per check; exit 0 only when all pass.
set -u
REPO=$(cd "$(dirname "$0")/../.." && pwd)
. "$REPO/utility-os/versions.env"
WORK=${UOS_WORK:-$HOME/uos-work}
OUT=$WORK/output
TFTP=$REPO/scriptexecute64
FAILS=0
ok() { echo "ok   $*"; }
fail() { echo "FAIL $*"; FAILS=$((FAILS + 1)); }

# --- kernel
REQUIRED="CONFIG_MODULES CONFIG_SPI_BCM2835 CONFIG_SPI_SPIDEV CONFIG_RASPBERRYPI_GPIOMEM CONFIG_BCM_VCIO CONFIG_BCM2835_MBOX
CONFIG_RASPBERRYPI_FIRMWARE CONFIG_LEDS_GPIO CONFIG_LEDS_TRIGGER_TIMER CONFIG_LEDS_TRIGGER_HEARTBEAT CONFIG_LEDS_TRIGGER_DEFAULT_ON
CONFIG_GPIO_RASPBERRYPI_EXP CONFIG_MMC CONFIG_MMC_BLOCK CONFIG_MMC_SDHCI CONFIG_MMC_SDHCI_PLTFM CONFIG_MMC_SDHCI_IPROC
CONFIG_MMC_BCM2835 CONFIG_EXT4_FS CONFIG_VFAT_FS CONFIG_NLS_CODEPAGE_437 CONFIG_BCMGENET CONFIG_BROADCOM_PHY CONFIG_PACKET
CONFIG_UNIX CONFIG_INET CONFIG_FB CONFIG_FB_BCM2708 CONFIG_FRAMEBUFFER_CONSOLE CONFIG_VT CONFIG_VT_CONSOLE CONFIG_USB
CONFIG_USB_DWC2 CONFIG_USB_XHCI_HCD CONFIG_USB_XHCI_PCI CONFIG_PCIE_BRCMSTB CONFIG_USB_HID CONFIG_HID_GENERIC CONFIG_DEVTMPFS
CONFIG_BLK_DEV_INITRD CONFIG_RD_XZ CONFIG_COMPAT CONFIG_DEBUG_FS CONFIG_BCM2835_WDT CONFIG_BCM2711_THERMAL CONFIG_VIRTIO_MMIO
CONFIG_VIRTIO_NET CONFIG_ARM_PSCI_FW CONFIG_SERIAL_AMBA_PL011 CONFIG_SERIAL_AMBA_PL011_CONSOLE"
CONFIG=$OUT/build/linux-custom/.config
if [ -f "$CONFIG" ]; then
    missing=
    for opt in $REQUIRED; do
        grep -qx "$opt=y" "$CONFIG" || missing="$missing $opt"
    done
    if [ -z "$missing" ]; then ok "kernel: every option the OS needs is built in"; else fail "kernel: not built in:$missing"; fi
    if grep -qx 'CONFIG_LOCALVERSION="-cmprovision"' "$CONFIG"; then ok "kernel: LOCALVERSION -cmprovision"; else fail "kernel: LOCALVERSION"; fi
    if grep -qx 'CONFIG_IPV6=m' "$CONFIG"; then ok "kernel: the defconfig's modules stay modules"; else fail "kernel: CONFIG_IPV6 is no longer =m"; fi
else
    fail "kernel: no $CONFIG (run build.sh first)"
fi

# --- contents
TARGET=$OUT/target
set -f
missing=
for cmd in $(grep -v '^#' "$REPO/utility-os/legacy-commands.txt") pinctrl; do
    found=
    for d in bin sbin usr/bin usr/sbin; do
        if [ -e "$TARGET/$d/$cmd" ] || [ -L "$TARGET/$d/$cmd" ]; then found=1; fi
    done
    [ -n "$found" ] || missing="$missing $cmd"
done
set +f
if [ -z "$missing" ]; then ok "contents: every command of the legacy OS"; else fail "contents: missing:$missing"; fi
if [ -d "$TARGET/lib/modules" ]; then fail "contents: kernel modules in the image"; else ok "contents: no kernel modules"; fi
SCRIPTS=$(ls "$TARGET/etc/init.d" | tr '\n' ' ')
case " $SCRIPTS " in
    *telnet*|*ssh*|*dropbear*|*httpd*|*ftpd*|*inetd*|*crond*) fail "contents: a service the legacy OS did not start: $SCRIPTS" ;;
    *" S99scriptexec "*) ok "contents: init scripts $SCRIPTS" ;;
    *) fail "contents: no S99scriptexec among $SCRIPTS" ;;
esac
if grep -qx 'CONFIG_TELNETD=y' "$OUT"/build/busybox-*/.config; then fail "contents: BusyBox has telnetd"; else ok "contents: no telnetd"; fi

# --- size
size=$(( $(wc -c < "$TFTP/kernel8.img") + $(wc -c < "$TFTP/scriptexecute.img") ))
mib=$(( (size + 1048575) / 1048576 ))
before=$(git -C "$REPO" show HEAD:scriptexecute64/MANIFEST 2>/dev/null | awk '$0 ~ /^file=/ && ($3 == "kernel8.img" || $3 == "scriptexecute.img") { s += $2 } END { if (s) printf "%d", (s + 1048575) / 1048576 }')
if [ "$size" -le 33554432 ]; then ok "size: kernel8.img + scriptexecute.img = $mib MiB${before:+ (committed: $before MiB)}"; else fail "size: $mib MiB, over 32 MiB"; fi

# --- manifest
listed=$(sed -n 's/^file=\([0-9a-f]*\) \([0-9]*\) \(.*\)$/\3 \1 \2/p' "$TFTP/MANIFEST")
present=$(cd "$TFTP" && find . -type f ! -name MANIFEST | sed 's#^\./##' | LC_ALL=C sort | while IFS= read -r f; do
    printf '%s %s %s\n' "$f" "$(sha256sum "$f" | cut -d' ' -f1)" "$(wc -c < "$f" | tr -d ' ')"; done)
if [ "$listed" = "$present" ]; then ok "manifest: lists exactly the files"; else fail "manifest: differs from the files"; fi

# --- USB uses the same OS without changing its network boot files.
for directory in scriptexecute64 scriptexecute; do
    if PATH="$OUT/host/bin:$OUT/host/sbin:$PATH" python3 "$REPO/tools/utility-os/usb_image.py" --check "$REPO/$directory"; then
        ok "USB: $directory ramdisk matches its kernel, initramfs and boot configuration"
    else
        fail "USB: $directory ramdisk differs from the utility OS"
    fi
done

# --- qemu
SMOKE=$WORK/smoke
rm -rf "$SMOKE" && mkdir -p "$SMOKE"
python3 "$REPO/tools/utility-os/smoke/server.py" "$SMOKE" "$REPO/tools/utility-os/smoke/check.sh" "$REPO/public/tools/ledpattern" \
    > "$SMOKE/server.log" 2>&1 &
SERVER=$!
for i in $(seq 50); do [ -s "$SMOKE/port" ] && break; sleep 0.2; done
PORT=$(cat "$SMOKE/port" 2>/dev/null)
APPEND="console=ttyAMA0 $(sed "s#http://172.20.0.1/#http://10.0.2.2:$PORT/#" "$TFTP/cmdline.txt")"
timeout 900 qemu-system-aarch64 -M virt -cpu cortex-a72 -smp 2 -m 1024 -nographic -no-reboot \
    -kernel "$TFTP/kernel8.img" -initrd "$TFTP/scriptexecute.img" -append "$APPEND" \
    -netdev user,id=n0 -device virtio-net-device,netdev=n0 > "$WORK/qemu.log" 2>&1
rc=$?
kill "$SERVER" 2>/dev/null
if [ "$rc" = 0 ]; then ok "qemu: the module powered off by itself"; else fail "qemu: exit $rc (124: no power-off in 15 minutes), see $WORK/qemu.log"; fi
mkdir -p "$REPO/tests/fixtures/utility-os"
python3 "$REPO/tools/utility-os/smoke/verify.py" "$SMOKE" "$UOS_VERSION" "$REPO/tests/fixtures/utility-os/messages.txt" "$UOS_LINUX_VERSION"
v=$?
[ "$v" = 0 ] || FAILS=$((FAILS + 1))

if [ "$FAILS" = 0 ]; then echo "test.sh: all checks passed"; exit 0; fi
echo "test.sh: $FAILS check(s) failed"
exit 1
