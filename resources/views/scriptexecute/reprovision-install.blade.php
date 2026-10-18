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
    sync
    cleanup
    mounted=0
    trap - EXIT
    echo 'agent installed: SSH access still depends on the image SSH policy'
)
RESULT=$?
[ "$RESULT" = 0 ] || echo "agent skipped: installation failed (exit $RESULT)"
exit 0
