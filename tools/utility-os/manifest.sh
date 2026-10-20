#!/bin/sh
# Write <dir>/MANIFEST (2.7.0): what the utility OS in <dir> is (the key=value arguments, in their order) and every file in
# it with its SHA256 and size. Settings → Utility OS shows it, and tests/Unit/UtilityOsFilesTest.php checks it.
#   tools/utility-os/manifest.sh <dir> key=value...
set -eu
[ $# -ge 1 ] && [ -d "$1" ] || { echo "usage: manifest.sh <dir> key=value..." >&2; exit 2; }
DIR=$1
shift
{
    echo "# cmprovision utility OS: what it is and its files (tools/utility-os/manifest.sh)"
    for kv in "$@"; do
        printf '%s\n' "$kv"
    done
    (cd "$DIR" && find . -type f ! -path ./MANIFEST ! -path ./MANIFEST.new | sed 's#^\./##' | LC_ALL=C sort | while IFS= read -r f; do
        printf 'file=%s %s %s\n' "$(sha256sum "$f" | cut -d' ' -f1)" "$(wc -c < "$f" | tr -d ' ')" "$f"
    done)
} > "$DIR/MANIFEST.new"
mv "$DIR/MANIFEST.new" "$DIR/MANIFEST"
