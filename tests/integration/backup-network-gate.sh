#!/bin/sh
# Verify backup/restore refusal only in private mounts, without opening the host database or services.
set -eu
if [ "${1:-}" != --inner ]; then
    [ "$(id -u)" = 0 ] || { echo 'root required' >&2; exit 1; }
    exec unshare --net --mount --pid --fork --mount-proc sh "$0" --inner
fi
mount --make-rprivate /
mount -t tmpfs -o mode=755 tmpfs /run
mount -t tmpfs -o mode=755 tmpfs /var/lib
mount -t tmpfs -o mode=1777 tmpfs /tmp
mkdir -p /run/cmprovision-network /var/lib/cmprovision-network/transactions
: >/run/cmprovision-network/pending
if sh "$(dirname "$0")/backup.sh" /tmp/backups >/tmp/backup.out 2>&1; then
    echo 'backup incorrectly accepted a pending network change' >&2; exit 1
fi
grep -Fq 'pending network change' /tmp/backup.out
[ ! -e /tmp/backups ]
rm /run/cmprovision-network/pending
: >/var/lib/cmprovision-network/transactions/pending.json
if sh "$(dirname "$0")/backup.sh" /tmp/backups >/tmp/backup.out 2>&1; then
    echo 'backup incorrectly accepted durable pending state' >&2; exit 1
fi
mkdir /tmp/incomplete
: >/tmp/incomplete/app.tar.gz
: >/tmp/incomplete/.incomplete
if sh "$(dirname "$0")/restore.sh" /tmp/incomplete >/tmp/restore.out 2>&1; then
    echo 'restore incorrectly accepted an incomplete backup' >&2; exit 1
fi
grep -Fq 'backup did not finish' /tmp/restore.out
rm /tmp/incomplete/.incomplete
if sh "$(dirname "$0")/restore.sh" /tmp/incomplete >/tmp/restore.out 2>&1; then
    echo 'restore incorrectly accepted durable pending network state' >&2; exit 1
fi
grep -Fq 'pending network change' /tmp/restore.out
printf '%s\n' 'PASS: pending network changes and incomplete snapshots fail closed'
