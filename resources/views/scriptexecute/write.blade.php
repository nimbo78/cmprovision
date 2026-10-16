{{-- The image write (2.6.1), included in scriptexecute.blade.php: download, decompress and write in one stream; a broken
     connection is resumed where it broke, and the partition table (the first MiB) is written last, so a module that loses
     power mid-write does not boot a half-written image. What goes wrong ends up in $WRITE_LOG, which the module sends to
     the server when the write fails. ImageWriteScriptTest runs this under BusyBox sh. --}}
IMAGE_URL={!! escapeshellarg($image_url) !!}
IMAGE_BYTES={{ (int) $image_bytes }}
WORK=${WRITE_WORK:-/tmp}
WRITE_LOG=${WRITE_LOG:-/tmp/dd.log}
FETCH_QUIET_SECONDS=${FETCH_QUIET_SECONDS:-600}
FETCH_PAUSE=${FETCH_PAUSE:-5}
PROGRESS_DETAIL={!! escapeshellarg($resume['detail'] ?? '') !!}
PROGRESS_BASE_SECTORS={{ (int) ($resume['base_sectors'] ?? 0) }}

# Byte counts of big images pass 2^31, and the shell's integers may be 32-bit: awk adds and compares them
bytes_add() { awk -v a="$1" -v b="$2" 'BEGIN { printf "%.0f\n", a + b }'; }
bytes_at_least() { awk -v a="$1" -v b="$2" 'BEGIN { exit !(a >= b) }'; }

# fetch_image <from byte>: the image file from that byte to its end on stdout. When the connection breaks, curl starts
# again where it broke (-C), so the decompressor and dd downstream see one stream; a server that answers with the whole
# file instead of the rest makes curl fail (33) rather than repeat the beginning. No --speed-limit: while dd writes a long
# run of zeros curl rightly waits for minutes; TCP keepalive finds a dead connection. curl counts the bytes
# (%{size_download}) because BusyBox dd tells no byte count. A decompressor that gave up shows as a write error (23).
fetch_image() {
    got=$1
    quiet_since=$(date +%s)
    while :; do
        curl -f -s -S -g --keepalive-time 15 --connect-timeout 10 -D "$WORK/fetch.hdr" -C "$got" \
            -w '%{stderr}FETCHED %{size_download}\n' "$IMAGE_URL" 2>"$WORK/fetch.err"
        rc=$?
        n=$(sed -n 's/^FETCHED \([0-9][0-9]*\)$/\1/p' "$WORK/fetch.err" | tail -n 1)
        n=${n:-0}
        grep -v '^FETCHED ' "$WORK/fetch.err" >&2
        got=$(bytes_add "$got" "$n")
        bytes_at_least "$n" 1 && quiet_since=$(date +%s)
        case $rc in
            0)
                bytes_at_least "$got" "$IMAGE_BYTES" && return 0
                # nothing more, although the file is not complete: it is shorter than the script was told
                if ! bytes_at_least "$n" 1; then
                    echo "the image on the server ends at $got of $IMAGE_BYTES bytes (curl exit code 0)" >>"$WRITE_LOG"
                    return 1
                fi ;;
            23|33)
                echo "curl exit code $rc" >>"$WRITE_LOG"
                return $rc ;;
            22)
                code=$(awk '/^HTTP\//{ c = $2 } END { print c }' "$WORK/fetch.hdr")
                case $code in
                    5??) ;;
                    *)
                        echo "curl exit code 22 (HTTP $code)" >>"$WRITE_LOG"
                        return 22 ;;
                esac ;;
        esac
        if [ $(( $(date +%s) - quiet_since )) -ge "$FETCH_QUIET_SECONDS" ]; then
            echo "no data from the provisioning server for $((FETCH_QUIET_SECONDS / 60)) minutes (curl exit code $rc)" >>"$WRITE_LOG"
            return 1
        fi
        echo "download interrupted at $got of $IMAGE_BYTES bytes (curl exit $rc), resuming" | tee -a "$WRITE_LOG" >&2
        progress_note "$got" "$IMAGE_BYTES" "$rc"
        sleep "$FETCH_PAUSE"
    done
}

# write_plain: the first MiB of the stream to $WORK/head (write_image writes it last), the rest straight to the storage
write_plain() {
    dd of="$WORK/head" bs=1M count=1 iflag=fullblock 2>>"$WRITE_LOG" || return 1
    dd of="$STORAGE" bs=1M seek=1 iflag=fullblock conv=fsync,notrunc 2>>"$WRITE_LOG"
}
@if ($pieces)

PIECES={{ (int) $pieces['count'] }}
PIECE_MB={{ (int) $pieces['mb'] }}

# write_pieces <first piece>: the image after its first MiB in pieces of PIECE_MB MiB, each on the storage (fsync) before
# the next starts. Piece k-1 counts as durable once piece k got data: the decompressor checked the CRC of piece k-1's
# gzip member before it gave out the first byte of piece k. The mark is in MiB of the image ($WORK/durable). A piece
# that came short ends the write, unless it is the last.
write_pieces() {
    k=$1
    while [ "$k" -lt "$PIECES" ]; do
        dd of="$STORAGE" bs=1M seek=$((1 + k * PIECE_MB)) count="$PIECE_MB" iflag=fullblock conv=fsync,notrunc 2>"$WORK/piece.dd"
        rc=$?
        cat "$WORK/piece.dd" >>"$WRITE_LOG"
        [ $rc -eq 0 ] || return $rc
        records=$(sed -n 's/^\([0-9]*\)+\([0-9]*\) records in$/\1 \2/p' "$WORK/piece.dd")
        full=${records%% *}
        part=${records##* }
        [ $(( ${full:-0} + ${part:-0} )) -gt 0 ] || return 1
        if [ "$k" -gt "$1" ]; then
            echo $((1 + k * PIECE_MB)) >"$WORK/durable"
            progress_mark write "$PROGRESS_DETAIL" "" "$((1 + k * PIECE_MB))"
        fi
        [ "${full:-0}" -eq "$PIECE_MB" ] || [ $((k + 1)) -eq "$PIECES" ] || return 1
        k=$((k + 1))
    done
}
@endif
@if ($resume)

# write_head_from_script: the first MiB of the image (gzip member 0 of the pieces copy), as it came with this script
write_head_from_script() {
    base64 -d >"$WORK/head.gz" << "CMPROVISIONINGEOF"
{!! $resume['head'] !!}
CMPROVISIONINGEOF
    gzip -dc "$WORK/head.gz" >"$WORK/head" 2>>"$WRITE_LOG" && [ $(( $(wc -c <"$WORK/head") )) -eq 1048576 ]
}

# storage_unchanged: the first MiB on the storage is as a write that stopped leaves it: zero (it is written last), or this
# image's own when the write got through its last step. Anything else was written since (flashed elsewhere, another card):
# going on would mix two images.
storage_unchanged() {
    dd if="$STORAGE" of="$WORK/stored-head" bs=1M count=1 2>>"$WRITE_LOG" || return 1
    [ $(( $(tr -d '\000' <"$WORK/stored-head" | wc -c) )) -eq 0 ] || cmp -s "$WORK/stored-head" "$WORK/head"
}
@endif

# write_image: the whole write; 0 once the image and, last, its partition table are on the storage
write_image() {
    : >"$WRITE_LOG"
@if ($resume)
    write_head_from_script || { echo "the first MiB in the script is broken" >>"$WRITE_LOG"; return 1; }
    storage_unchanged || { echo "the storage was written after the write stopped" >>"$WRITE_LOG"; return 1; }
@else
    progress_mark write 'Discarding old data'
    echo Sending BLKDISCARD to $STORAGE
    blkdiscard -v $STORAGE || true
@endif
    # TRIM does not promise zeros on eMMC: the first MiB is cleared on purpose, and written last
    dd if=/dev/zero of="$STORAGE" bs=1M count=1 conv=fsync,notrunc 2>>"$WRITE_LOG" || return 1
    echo Writing image from $IMAGE_URL to $STORAGE
    progress_start write 7 "$PROGRESS_DETAIL" "$PROGRESS_BASE_SECTORS"
@if ($resume)
    fetch_image {{ (int) $resume['from_byte'] }} | { {!! $decompress !!} 2>>"$WRITE_LOG"; } | write_pieces {{ (int) $resume['piece'] }}
@elseif ($pieces)
    fetch_image 0 | { {!! $decompress !!} 2>>"$WRITE_LOG"; } | { dd of="$WORK/head" bs=1M count=1 iflag=fullblock 2>>"$WRITE_LOG" && write_pieces 0; }
@else
    fetch_image 0 | { {!! $decompress !!} 2>>"$WRITE_LOG"; } | write_plain
@endif
    status=$?
    progress_stop
    [ $status -eq 0 ] || return $status
    dd if="$WORK/head" of="$STORAGE" bs=1M count=1 conv=fsync,notrunc 2>>"$WRITE_LOG"
}
