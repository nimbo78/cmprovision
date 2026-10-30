#!/bin/sh
# cmprovision: the provisioner's mark over LLDP for the first boots of this system, left by the re-provision agent
# (App\Services\Sensor\ModuleMark). Every boot counts: up to the last one the switch hears the port description
# "cmprovision mark <station> <boot>/<boots> <code>", the code an HMAC of "<station> <boot>/<boots>" with this device's own
# key, new on every boot. The boot after the last removes the mark, its key and this service. MARK_ROOT moves the paths
# (the test runs it so).
set -u
R=${MARK_ROOT:-}
DIR=$R/usr/local/lib/cmprovision-mark
CONF=$R/etc/lldpd.d/cmprovision-mark.conf
UNIT=$R/etc/systemd/system/cmprovision-mark.service
WANTS=$R/etc/systemd/system/multi-user.target.wants/cmprovision-mark.service

value() { sed -n "s/^$1=//p" "$DIR/settings" 2>/dev/null | head -n 1; }

forget() {
    rm -f "$CONF" "$WANTS" "$UNIT"
    rm -rf "$DIR"
    systemctl daemon-reload >/dev/null 2>&1 || true
    # lldpcli cannot take a port description back: lldpd starts again without it
    systemctl try-restart lldpd.service >/dev/null 2>&1 || true
    echo "cmprovision mark: $1"
}

station=$(value STATION)
boots=$(value BOOTS)
case "$station" in ''|*[!A-Za-z0-9._-]*) forget 'no settings, the mark is gone'; exit 0 ;; esac
case "$boots" in ''|*[!0-9]*) forget 'no settings, the mark is gone'; exit 0 ;; esac

boot=$(cat "$DIR/boot" 2>/dev/null || true)
case "$boot" in ''|*[!0-9]*) boot=0 ;; esac
boot=$((boot + 1))
if [ "$boot" -gt "$boots" ]; then
    forget "the last of its $boots boots has passed, the mark is gone"
    exit 0
fi
# a boot that cannot be counted is not marked: the mark must not outlive its boots
{ printf '%s\n' "$boot" > "$DIR/boot.new" && mv -f "$DIR/boot.new" "$DIR/boot"; } || { echo 'cmprovision mark: cannot count the boots'; exit 0; }

key=$(cat "$DIR/key" 2>/dev/null || true)
case "$key" in ''|*[!0-9a-f]*) echo "cmprovision mark: boot $boot of $boots, no key"; exit 0 ;; esac
text="$station $boot/$boots"
code=$(printf '%s' "$text" | openssl dgst -sha256 -mac HMAC -macopt "hexkey:$key" 2>/dev/null | awk '{print $NF}' | cut -c1-16)
case "$code" in *[!0-9a-f]*) code= ;; esac
[ "${#code}" -eq 16 ] || { echo "cmprovision mark: boot $boot of $boots, openssl made no code"; exit 0; }

mkdir -p "$R/etc/lldpd.d"
printf '# cmprovision: the provisioner'"'"'s mark, boot %s of %s (gone after the last)\nconfigure lldp portdescription "cmprovision mark %s %s"\n' \
    "$boot" "$boots" "$text" "$code" > "$CONF.new" && mv -f "$CONF.new" "$CONF"
lldpcli -c "$CONF" >/dev/null 2>&1 || true
echo "cmprovision mark: boot $boot of $boots"
