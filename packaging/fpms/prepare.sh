#!/bin/sh
# Materialize the exact upstream base plus our reviewed patch. Never modifies an installed FPMS.
set -eu
DEST=${1:?new source directory required}
PATCH_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
BASE=8beeba40a0f9d4f8045ac556a5b0780a1ce9028e
[ ! -e "$DEST" ] || { echo "Source directory already exists: $DEST" >&2; exit 1; }
git init -q "$DEST"
git -C "$DEST" fetch --quiet --depth=1 https://github.com/WLAN-Pi/wlanpi-fpms.git "$BASE"
git -C "$DEST" checkout --quiet --detach "$BASE"
test "$(git -C "$DEST" rev-parse HEAD)" = "$BASE"
git -C "$DEST" apply --check "$PATCH_DIR/portable-cmprovision.patch"
git -C "$DEST" apply "$PATCH_DIR/portable-cmprovision.patch"
printf '%s\n' "$BASE" > "$DEST/.cmprovision-upstream"
