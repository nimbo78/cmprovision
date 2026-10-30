#!/bin/sh
# Failure to install remote re-provisioning does not fail the image write.
(
    set -e
    ROOT=$(mktemp -d /tmp/cmprovision-agent.XXXXXX)
    mounted=0
    cleanup() {
        if [ "$mounted" = 1 ]; then umount "$ROOT" || return 1; fi
        rmdir "$ROOT"
    }
    trap cleanup EXIT
    mount -t ext4 "$PART2" "$ROOT" || { echo 'agent skipped: second partition is not mountable ext4'; exit 0; }
    mounted=1
    [ -d "$ROOT/etc/ssh" ] && [ ! -L "$ROOT/etc" ] && [ ! -L "$ROOT/etc/ssh" ] || { echo 'agent skipped: no SSH configuration'; exit 0; }
    for p in usr usr/local usr/local/sbin root root/.ssh; do
        [ ! -L "$ROOT/$p" ] || { echo 'agent skipped: installation path contains a symlink'; exit 0; }
        mkdir -p "$ROOT/$p"
    done
    [ ! -L "$ROOT/usr/local/sbin/cmprovision-agent" ] && [ ! -L "$ROOT/root/.ssh/authorized_keys" ] || { echo 'agent skipped: destination is a symlink'; exit 0; }
    cat >"$ROOT/usr/local/sbin/cmprovision-agent" <<'CMPROVISION_AGENT_EOF'
{!! $agent !!}
CMPROVISION_AGENT_EOF
    chmod 0755 "$ROOT/usr/local/sbin/cmprovision-agent"
    chmod 0700 "$ROOT/root/.ssh"
    touch "$ROOT/root/.ssh/authorized_keys"
    KEY={!! escapeshellarg($public) !!}
    BLOB=$(printf '%s\n' "$KEY" | awk '{print $2}')
    if ! grep -Fq "$BLOB" "$ROOT/root/.ssh/authorized_keys"; then
        printf '\nrestrict,command="/usr/local/sbin/cmprovision-agent" %s\n' "$KEY" >>"$ROOT/root/.ssh/authorized_keys"
    fi
    chmod 0600 "$ROOT/root/.ssh/authorized_keys"
    chown 0:0 "$ROOT/usr/local/sbin/cmprovision-agent" "$ROOT/root/.ssh" "$ROOT/root/.ssh/authorized_keys"
@if ($mark !== null && isset($mark['skip']))
    echo {!! escapeshellarg('mark skipped: '.$mark['skip']) !!}
@elseif ($mark !== null)
    # The provisioner's mark over LLDP (2.23.0): a service of its own names the provisioner to the switch for the first
    # boots and then removes itself (scriptexecute/module-mark.blade.php). The module's key comes from the server: this
    # script runs under sh -v, which copies its lines into the log
    mark_install() {
        M=$ROOT/usr/local/lib/cmprovision-mark
        S=$ROOT/etc/systemd/system
        for p in usr/local/lib usr/local/lib/cmprovision-mark etc/systemd etc/systemd/system etc/systemd/system/multi-user.target.wants \
                 etc/systemd/system/cmprovision-mark.service; do
            [ ! -L "$ROOT/$p" ] || { echo 'mark skipped: installation path contains a symlink'; return 0; }
        done
        MARK_KEY=$(curl -fsS --max-time 20 --retry 3 {!! escapeshellarg($mark['url']) !!} 2>/dev/null) || MARK_KEY=
        case "$MARK_KEY" in ''|*[!0-9a-f]*) echo 'mark skipped: the server gave no key'; return 0 ;; esac
        [ "${#MARK_KEY}" -eq 64 ] || { echo 'mark skipped: the server gave no key'; return 0; }
        mkdir -p "$M" "$S/multi-user.target.wants" || return 1
        chmod 0700 "$M" || return 1
        rm -f "$M/boot" "$M/boot.new"
        cat >"$M/mark" <<'CMPROVISION_MARK_EOF' || return 1
{!! $markScript !!}
CMPROVISION_MARK_EOF
        printf 'STATION=%s\nBOOTS=%s\n' {!! escapeshellarg($mark['station']) !!} {!! (int) $mark['boots'] !!} >"$M/settings" || return 1
        (umask 077; printf '%s\n' "$MARK_KEY" >"$M/key") || return 1
        cat >"$S/cmprovision-mark.service" <<'CMPROVISION_MARK_EOF' || return 1
[Unit]
Description=cmprovision: the provisioner's mark over LLDP for the first boots
After=lldpd.service

[Service]
Type=oneshot
ExecStart=/usr/local/lib/cmprovision-mark/mark

[Install]
WantedBy=multi-user.target
CMPROVISION_MARK_EOF
        ln -sfn /etc/systemd/system/cmprovision-mark.service "$S/multi-user.target.wants/cmprovision-mark.service" || return 1
        chmod 0755 "$M/mark" && chmod 0600 "$M/key" "$M/settings" && chmod 0644 "$S/cmprovision-mark.service" || return 1
        chown -R 0:0 "$M" "$S/cmprovision-mark.service" || return 1
        echo 'mark installed: the switch hears it for the first {{ (int) $mark['boots'] }} boots'
    }
    mark_install || {
        rm -rf "$ROOT/usr/local/lib/cmprovision-mark"
        rm -f "$ROOT/etc/systemd/system/cmprovision-mark.service" "$ROOT/etc/systemd/system/multi-user.target.wants/cmprovision-mark.service"
        echo 'mark skipped: installation failed'
    }
@endif
    sync
    cleanup
    mounted=0
    trap - EXIT
    echo 'agent installed: SSH access still depends on the image SSH policy'
)
RESULT=$?
[ "$RESULT" = 0 ] || echo "agent skipped: installation failed (exit $RESULT)"
exit 0
