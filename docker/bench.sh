#!/bin/sh
# Helper for the Docker test bench. Run on the Docker host from anywhere:
#   docker/bench.sh sync    [bullseye|trixie]   copy the source tree into the container's app dir
#   docker/bench.sh up      [bullseye|trixie]   build the image if needed and start the container
#   docker/bench.sh restart [bullseye|trixie]   restart (the queue worker only sees new code after this)
#   docker/bench.sh test    [bullseye|trixie] [phpunit args]   run the test-suite on in-memory SQLite
#   docker/bench.sh shell   [bullseye|trixie]   shell inside the container as the app user
#   docker/bench.sh deb     [bullseye|trixie]   build the .deb from a clean export (default: bullseye)
#   docker/bench.sh logs    [bullseye|trixie]
# The source tree is the repository checkout this script lives in. Runtime state in the app dir
# (.env, database, storage, uploads, vendor) is left alone by sync.
set -e
HERE=$(cd "$(dirname "$0")" && pwd)
SRC=$(dirname "$HERE")
cd "$HERE"
export BENCH_UID=$(id -u) BENCH_GID=$(id -g)

cmd=${1:-help}; [ $# -gt 0 ] && shift
svc=bullseye
case "${1:-}" in bullseye|trixie) svc=$1; shift ;; esac
APPDIR="$HERE/$svc/app"
EXCLUDES="--exclude=/.git --exclude=/.serena --exclude=/.remember --exclude=/.vscode --exclude=/node_modules --exclude=/docker --exclude=/.env --exclude=/vendor"

sync_app() {
    mkdir -p "$APPDIR"
    # shellcheck disable=SC2086
    rsync -a --delete $EXCLUDES \
        --exclude=/storage --exclude=/bootstrap/cache --exclude=/database/database.sqlite --exclude=/public/uploads \
        "$SRC/" "$APPDIR/"
    # storage/ and bootstrap/cache hold runtime state: create them from the repo skeleton once.
    [ -d "$APPDIR/storage" ] || cp -a "$SRC/storage" "$APPDIR/storage"
    [ -d "$APPDIR/bootstrap/cache" ] || cp -a "$SRC/bootstrap/cache" "$APPDIR/bootstrap/cache"
    echo "synced $SRC -> $APPDIR"
}

case "$cmd" in
    sync)    sync_app ;;
    up)      sync_app; docker compose up -d --build "$svc" ;;
    restart) docker compose restart "$svc" ;;
    test)    docker compose exec -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -u "$BENCH_UID:$BENCH_GID" "$svc" php artisan test "$@" ;;
    shell)   docker compose exec -u "$BENCH_UID:$BENCH_GID" "$svc" bash ;;
    logs)    docker compose logs --tail=100 "$svc" ;;
    deb)     # clean export without vendor/: the Makefile installs the production dependencies itself
             BUILD="$HERE/build"; rm -rf "$BUILD"; mkdir -p "$BUILD/src"
             # shellcheck disable=SC2086
             rsync -a $EXCLUDES "$SRC/" "$BUILD/src/"
             docker compose run --rm -u "$BENCH_UID:$BENCH_GID" -v "$BUILD:/build" -w /build/src --entrypoint sh "$svc" \
                 -c 'export HOME=/tmp COMPOSER_HOME=/tmp/composer; mkdir -p public/uploads; dpkg-buildpackage -b -us -uc' \
             && ls -la "$BUILD"/*.deb ;;
    *)       sed -n '2,11p' "$0" ;;
esac
