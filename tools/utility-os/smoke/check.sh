#!/bin/sh
# Runs inside the utility OS under QEMU (tools/utility-os/test.sh; smoke/server.py fills in __SERVER__): what the new OS
# says, back to the test server, then power off. No set -e: every check reports, a failing one too.
S=__SERVER__
R=/tmp/result
cd /tmp || exit 1
: > "$R"
section() { printf '[%s]\n' "$1" >> "$R"; }
get() { curl -s -f -o "$2" "$S/samples/$1"; }

section uos
cat /etc/cmprovision-uos >> "$R"
section kernel
uname -r >> "$R"

# Run the shipped agent with this image's BusyBox and tools, without a physical USB controller in QEMU.
section usb-agent
diag_proc=/tmp/diagnostic-proc
mkdir -p "$diag_proc/device-tree" "$diag_proc/sys/kernel/random"
printf '1000000000000001\000' > "$diag_proc/device-tree/serial-number"
cp /proc/sys/kernel/random/boot_id "$diag_proc/sys/kernel/random/boot_id"
for fact in uptime meminfo partitions; do ln -s "/proc/$fact" "$diag_proc/$fact"; done
diag_boot=$(cat "$diag_proc/sys/kernel/random/boot_id")
diag_nonce=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
printf 'CMPDIAG1 HELLO %s\nCMPDIAG1 REPORT %s %s discovery\n' "$diag_nonce" "$diag_nonce" "$diag_boot" \
    | CMP_DIAG_PROC="$diag_proc" CMP_DIAG_STATE=/tmp/diagnostic-state /usr/sbin/cmprovision-diagnostics-agent --stdio >> "$R" 2>/dev/null

# lldpd runs and carries the name and the description S61lldpname gave it (it works in the background: asked for a while)
section lldp
n=0
while [ "$n" -lt 40 ]; do
    lldpcli show chassis > /tmp/lldp.txt 2>&1 && grep -q 'SysName:.*cm-' /tmp/lldp.txt && break
    n=$((n + 1)); sleep 1
done
grep -E 'ChassisID|SysName|SysDescr' /tmp/lldp.txt >> "$R"

# every tool starts: a missing library or a wrong binary shows as 126, 127, 132, 134 or 139, or in the first line
section versions
while read -r name args; do
    $name $args < /dev/null > /tmp/out 2>&1
    printf '%s rc=%s %s\n' "$name" "$?" "$(head -n 1 /tmp/out)" >> "$R"
done <<'EOF'
busybox
curl --version
lldpcli -v
e2fsck -V
mke2fs -V
resize2fs
tune2fs
parted --version
partprobe --version
sfdisk --version
blkdiscard --version
lsblk --version
flashrom --version
mmc --help
bsdtar --version
systool -h
pinctrl -h
vcgencmd version
vcmailbox
dtoverlay -h
dtmerge
hexdump --version
EOF

# the decompressors of the module's write (ScriptExecuteController::DECOMPRESSORS): a whole image, one cut short, garbage
get garbage /tmp/garbage
for kind in gz xz bz2; do
    case $kind in
        gz) dec="gzip -dc" ;;
        xz) dec="xz -dc" ;;
        bz2) dec="bunzip2 -dc" ;;
    esac
    get "image.$kind" /tmp/image
    get "cut.$kind" /tmp/cut
    section "$kind-whole"
    $dec < /tmp/image 2>/dev/null | sha256sum | cut -d' ' -f1 >> "$R"
    section "$kind-cut"
    $dec < /tmp/cut 2>&1 > /dev/null | head -n 5 >> "$R"
    section "$kind-garbage"
    $dec < /tmp/garbage 2>&1 > /dev/null | head -n 5 >> "$R"
done

# dd as the write uses it, and on a full storage
mkdir -p /tmp/full && mount -t tmpfs -o size=64k tmpfs /tmp/full
section dd-full
dd if=/dev/zero of=/tmp/full/x bs=4k count=64 2>&1 | head -n 5 >> "$R"
section dd-records
dd if=/dev/zero of=/dev/null bs=1M count=3 >> "$R" 2>&1
section dd-flags
dd if=/dev/zero of=/tmp/d bs=1M count=2 iflag=fullblock conv=fsync,notrunc 2>/dev/null
echo "rc=$?" >> "$R"

# EEPROM contract: real writes/no-ops on a file-backed dummy SPI chip, never host hardware.
echo hi > /tmp/pieeprom.bin
section sha256-mismatch
echo "0000000000000000000000000000000000000000000000000000000000000000  pieeprom.bin" | sha256sum -c >> "$R" 2>&1
dd if=/dev/zero of=/tmp/eeprom-image.bin bs=131072 count=1 2>/dev/null
section flashrom-written
flashrom -p dummy:emulate=M25P10.RES,image=/tmp/eeprom-chip.bin -c M25P10 -w /tmp/eeprom-image.bin >> "$R" 2>&1
echo "rc=$?" >> "$R"
section flashrom-identical
flashrom -p dummy:emulate=M25P10.RES,image=/tmp/eeprom-chip.bin -c M25P10 -w /tmp/eeprom-image.bin >> "$R" 2>&1
echo "rc=$?" >> "$R"
section flashrom-missing
flashrom -p dummy:bus=spi >> "$R" 2>&1
echo "rc=$?" >> "$R"

# small tools of the module's script
section od
printf '\000\000\000\003' > /tmp/bm
od -An -tu1 -j3 -N1 /tmp/bm | tr -d ' ' >> "$R"
section base64
printf 'aGVsbG8=' | base64 -d >> "$R"
echo >> "$R"
section zeros
head -c 1000 /dev/zero | tr -d '\000' | wc -c | tr -d ' ' >> "$R"
section awk
awk 'BEGIN { printf "%.0f\n", 2^40 + 1 }' >> "$R"
section cmp
cmp -s /tmp/d /tmp/d
echo "rc=$?" >> "$R"
section curl
curl -s -o /dev/null -w '%{http_code}' "$S/missing" >> "$R"
curl -s -f -o /dev/null "$S/missing"
echo " rc=$?" >> "$R"

# the 32-bit LED helper the module downloads runs on this 64-bit kernel
section ledpattern
get ledpattern /tmp/ledpattern && chmod +x /tmp/ledpattern
/tmp/ledpattern > /tmp/out 2>&1
printf 'rc=%s %s\n' "$?" "$(head -n 1 /tmp/out)" >> "$R"

curl -s --data-binary @"$R" "$S/result"
sync
poweroff -f
