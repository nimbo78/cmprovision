<?php

namespace App\Support;

/* The pieces copy of an image (2.6.1, App\Jobs\SplitImage): gzip member 0 is the first MiB of the image (the partition
   table, which the module writes last), every further member one piece of pieceMb() MiB of the image. Offsets in the
   image are bytes. */
class ImagePieces
{
    const HEAD_BYTES = 1048576;
    /* the job's timeout: a copy queued longer ago than this has stopped without a result */
    const SPLIT_TIMEOUT = 7200;

    public static function pieceMb(): int
    {
        return max(1, (int) config('cmprovision.split_piece_mb'));
    }

    /* Pieces after the first MiB of an image of $size bytes */
    public static function count(int $size, int $pieceMb): int
    {
        $piece = $pieceMb * 1048576;
        return intdiv(max(0, $size - self::HEAD_BYTES) + $piece - 1, $piece);
    }

    /* The piece a run goes on from when the image is durable up to $durable bytes; 0 when not even one piece is */
    public static function resumePiece(int $durable, int $pieceMb): int
    {
        return max(0, intdiv($durable - self::HEAD_BYTES, $pieceMb * 1048576));
    }

    /* Where piece $k starts in the image */
    public static function pieceStart(int $k, int $pieceMb): int
    {
        return self::HEAD_BYTES + $k * $pieceMb * 1048576;
    }

    /* "3.5 GB": how far a module got, for the pages and the log */
    public static function gb(int $bytes): string
    {
        return number_format($bytes / 1000000000, 1).' GB';
    }

    /* The step of a write that goes on after a restart */
    public static function resumedDetail(int $from, int $total): string
    {
        return 'Resumed after a restart at '.self::gb($from).' of '.self::gb($total);
    }
}
