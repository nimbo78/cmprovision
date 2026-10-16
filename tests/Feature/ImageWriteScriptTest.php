<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The image write of the module's script (resources/views/scriptexecute/write.blade.php) under BusyBox sh with the applets
 * the utility OS has: a stand-in curl serves the image from a directory and breaks the connection where a test says, the
 * "eMMC" is a file, and date and sleep run on a clock of their own.
 */
class ImageWriteScriptTest extends TestCase
{
    const MIB = 1048576;
    const URL = 'http://172.20.0.1/uploads/image';
    const APPLETS = ['gzip', 'xz', 'bunzip2', 'base64', 'sed', 'awk', 'wc', 'tail', 'tee', 'grep', 'cat', 'tr', 'cmp'];

    /* curl as the module sees it, serving $FAKE/www: -C, -D, -f and -w as curl does them. What goes wrong comes from
         $FAKE/breaks        one number per request: only that many bytes, then the connection breaks (exit 56)
         $FAKE/statuses      one HTTP status per request (4xx/5xx: exit 22, nothing delivered)
         $FAKE/ignore-range  a ranged request gets the whole file (curl gives up: exit 33) */
    const FAKE_CURL = <<<'SH'
printf '%s\n' "$*" >> "$FAKE/curl.log"
from=0; hdr=/dev/null; wfmt=; url=
while [ $# -gt 0 ]; do
    case "$1" in
        -C) from=$2; shift ;;
        -D) hdr=$2; shift ;;
        -w) wfmt=$2; shift ;;
        --keepalive-time|--connect-timeout|--max-time) shift ;;
        -*) ;;
        *) url=$1 ;;
    esac
    shift
done
written=0
done_with() {
    if [ -n "$wfmt" ]; then
        out=$(printf '%s' "$wfmt" | sed "s/%{size_download}/$written/; s/%{stderr}//")
        case "$wfmt" in
            '%{stderr}'*) printf "$out" >&2 ;;
            *) printf "$out" ;;
        esac
    fi
    exit "$1"
}
next() { [ -s "$1" ] || return 1; head -n 1 "$1"; sed -i 1d "$1"; }
status=$(next "$FAKE/statuses")
case "$status" in
    [45]??) printf 'HTTP/1.1 %s No\r\n\r\n' "$status" > "$hdr"; done_with 22 ;;
esac
file="$FAKE/www/${url##*/}"
[ -f "$file" ] || { printf 'HTTP/1.1 404 Not Found\r\n\r\n' > "$hdr"; done_with 22; }
if [ "$from" -gt 0 ] && [ -f "$FAKE/ignore-range" ]; then printf 'HTTP/1.1 200 OK\r\n\r\n' > "$hdr"; done_with 33; fi
if [ "$from" -gt 0 ]; then printf 'HTTP/1.1 206 Partial Content\r\n\r\n' > "$hdr"; else printf 'HTTP/1.1 200 OK\r\n\r\n' > "$hdr"; fi
rest=$(( $(wc -c < "$file") - from ))
cut=$(next "$FAKE/breaks")
if [ -n "$cut" ] && [ "$cut" -lt "$rest" ]; then
    tail -c +$((from + 1)) "$file" | head -c "$cut" || done_with 23
    written=$cut
    done_with 56
fi
tail -c +$((from + 1)) "$file" || done_with 23
written=$rest
done_with 0
SH;

    protected $dir, $busybox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->busybox = trim((string) shell_exec('command -v busybox 2>/dev/null'));
        $applets = $this->busybox ? explode("\n", (string) shell_exec(escapeshellarg($this->busybox).' --list')) : [];
        foreach (array_merge(['sh', 'dd'], self::APPLETS) as $applet)
            if (!in_array($applet, $applets, true))
                $this->markTestSkipped('needs busybox with '.$applet);
        if (!is_executable('/usr/bin/xz') || !is_executable('/usr/bin/bzip2'))
            $this->markTestSkipped('needs xz and bzip2 to make the test images');
        $this->dir = sys_get_temp_dir().'/cmprovision-write-'.uniqid();
        foreach (['bin', 'www', 'work'] as $sub)
            mkdir($this->dir.'/'.$sub, 0755, true);
        foreach (self::APPLETS as $applet)
            symlink($this->busybox, $this->dir.'/bin/'.$applet);
        // dd as in the utility OS, every call noted; the call with $FAKE_DD_FAIL among its arguments copies a whole piece
        // and then fails at the storage, as a failing eMMC does on its fsync
        $this->stub('dd', 'echo "$*" >> "$FAKE/dd.calls"'."\n"
            .'if [ -n "$FAKE_DD_FAIL" ]; then case " $* " in *" $FAKE_DD_FAIL "*)'."\n"
            .'    head -c 1048576 >/dev/null; printf "1+0 records in\n1+0 records out\ndd: fsync: Input/output error\n" >&2; exit 1 ;; esac; fi'."\n"
            .'exec '.escapeshellarg($this->busybox).' dd "$@"');
        file_put_contents($this->dir.'/clock', "1000000\n");
        $this->stub('date', 'cat "$FAKE/clock"');
        $this->stub('sleep', 'echo $(( $(cat "$FAKE/clock") + ${1%.*} )) > "$FAKE/clock"');
        $this->stub('blkdiscard', 'echo "blkdiscard $*" >> "$FAKE/calls"');
        $this->stub('curl', self::FAKE_CURL);
    }

    protected function tearDown(): void
    {
        if ($this->dir)
            File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    protected function stub($name, $body)
    {
        file_put_contents($this->dir.'/bin/'.$name, "#!/bin/sh\n".$body."\n");
        chmod($this->dir.'/bin/'.$name, 0755);
    }

    /** A disk image of $size bytes: a partition table in the first MiB, then data that compresses only in part */
    protected static function diskImage(int $size): string
    {
        $data = str_repeat("\x55", 512).str_repeat("\0", self::MIB - 512);
        for ($i = 0; strlen($data) < $size; $i++)
            $data .= $i % 64 < 48 ? hash('sha256', 'block '.$i, true) : str_repeat("\0", 32);
        return substr($data, 0, $size);
    }

    protected static function compress(string $data, string $kind): string
    {
        if ($kind === 'gz')
            return gzencode($data, 6);
        $in = tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($in, $data);
        $out = shell_exec(($kind === 'xz' ? '/usr/bin/xz -c ' : '/usr/bin/bzip2 -c ').escapeshellarg($in));
        unlink($in);
        return $out;
    }

    protected function serve(string $content): void
    {
        file_put_contents($this->dir.'/www/image', $content);
    }

    /** Renders the write step and runs write_image as the module's script does; its exit code */
    protected function write(array $vars = [], array $env = []): int
    {
        $vars = array_merge(['image_url' => self::URL, 'image_bytes' => filesize($this->dir.'/www/image'),
            'decompress' => 'gzip -dc', 'pieces' => null, 'resume' => null], $vars);
        $prelude = implode("\n", [
            'set -o pipefail',
            'STORAGE="$FAKE/emmc"',
            'progress_mark() { echo "mark $1|$2|$3|$4" >> "$FAKE/marks"; }',
            'progress_note() { echo "note $1|$2|$3" >> "$FAKE/marks"; }',
            'progress_start() { echo "start $1|$2|$3|$4" >> "$FAKE/marks"; }',
            'progress_stop() { echo stop >> "$FAKE/marks"; }',
        ]);
        file_put_contents($this->dir.'/write.sh', $prelude."\n".view('scriptexecute.write', $vars)->render()."\nwrite_image\n");
        $env = array_merge(['FAKE' => $this->dir, 'PATH' => $this->dir.'/bin:'.getenv('PATH'),
            'WRITE_WORK' => $this->dir.'/work', 'WRITE_LOG' => $this->dir.'/dd.log'], $env);
        $prefix = '';
        foreach ($env as $name => $value)
            $prefix .= $name.'='.escapeshellarg((string) $value).' ';
        exec($prefix.escapeshellarg($this->busybox).' sh '.escapeshellarg($this->dir.'/write.sh')
            .' >'.escapeshellarg($this->dir.'/console').' 2>&1', $out, $code);
        return $code;
    }

    protected function emmc(): string
    {
        return (string) @file_get_contents($this->dir.'/emmc');
    }

    protected function lines(string $file): array
    {
        return @file($this->dir.'/'.$file, FILE_IGNORE_NEW_LINES) ?: [];
    }

    protected function log(): string
    {
        return (string) @file_get_contents($this->dir.'/dd.log');
    }

    /** The -C offset of every request for the image */
    protected function imageRequests(): array
    {
        $requests = array_filter($this->lines('curl.log'), function ($line) { return str_contains($line, self::URL); });
        return array_values(array_map(function ($line) {
            return preg_match('/-C (\d+)/', $line, $m) ? (int) $m[1] : null;
        }, $requests));
    }

    public function test_an_unbroken_download_writes_the_image_and_the_partition_table_last()
    {
        $image = self::diskImage(3 * self::MIB + 4096);
        $this->serve(gzencode($image, 6));

        $this->assertSame(0, $this->write());

        $this->assertSame($image, $this->emmc());
        $this->assertSame([0], $this->imageRequests());
        $calls = $this->lines('dd.calls');
        $this->assertStringStartsWith('if=/dev/zero of='.$this->dir.'/emmc bs=1M count=1', $calls[0], 'the partition table is cleared first');
        $this->assertSame('if='.$this->dir.'/work/head of='.$this->dir.'/emmc bs=1M count=1 conv=fsync,notrunc', end($calls), 'and written last');
        $this->assertSame(['mark write|Discarding old data||', 'start write|7||0', 'stop'], $this->lines('marks'));
        $this->assertSame(['blkdiscard -v '.$this->dir.'/emmc'], $this->lines('calls'));
    }

    public static function kinds(): array
    {
        return ['gzip' => ['gz', 'gzip -dc'], 'xz' => ['xz', 'xz -dc'], 'bzip2' => ['bz2', 'bunzip2 -dc']];
    }

    #[DataProvider('kinds')]
    public function test_a_broken_connection_is_resumed_where_it_broke($kind, $decompress)
    {
        $image = self::diskImage(3 * self::MIB + 4096);
        $compressed = self::compress($image, $kind);
        $size = strlen($compressed);
        $this->serve($compressed);
        file_put_contents($this->dir.'/breaks', "5000\n".intdiv($size, 2)."\n");

        $this->assertSame(0, $this->write(['decompress' => $decompress]));

        $this->assertSame($image, $this->emmc());
        $second = 5000 + intdiv($size, 2);
        $this->assertSame([0, 5000, $second], $this->imageRequests());
        $this->assertContains("note 5000|$size|56", $this->lines('marks'));
        $this->assertContains("note $second|$size|56", $this->lines('marks'));
        $this->assertStringContainsString("download interrupted at 5000 of $size bytes (curl exit 56), resuming", $this->log());
    }

    public function test_until_the_last_step_the_storage_holds_no_partition_table()
    {
        $image = self::diskImage(3 * self::MIB + 4096);
        $compressed = gzencode($image, 6);
        $this->serve($compressed);
        file_put_contents($this->dir.'/breaks', intdiv(strlen($compressed), 2)."\n");
        file_put_contents($this->dir.'/statuses', "200\n".str_repeat("503\n", 400));   // then the server is gone

        $this->assertNotSame(0, $this->write([], ['FETCH_PAUSE' => '30']));

        $emmc = $this->emmc();
        $this->assertSame(str_repeat("\0", self::MIB), substr($emmc, 0, self::MIB), 'no partition table');
        $this->assertSame(substr($image, self::MIB, 4096), substr($emmc, self::MIB, 4096), 'while the image after it was written');
        $this->assertStringContainsString('no data from the provisioning server for 10 minutes (curl exit code 22)', $this->log());
    }

    public function test_a_5xx_answer_is_retried()
    {
        $image = self::diskImage(2 * self::MIB);
        $this->serve(gzencode($image, 6));
        file_put_contents($this->dir.'/statuses', "503\n");

        $this->assertSame(0, $this->write());

        $this->assertSame($image, $this->emmc());
        $this->assertSame([0, 0], $this->imageRequests());
    }

    public function test_a_4xx_answer_fails_at_once()
    {
        $this->serve(gzencode(self::diskImage(2 * self::MIB), 6));
        file_put_contents($this->dir.'/statuses', "404\n");

        $this->assertNotSame(0, $this->write());

        $this->assertSame([0], $this->imageRequests());
        $this->assertStringContainsString('curl exit code 22 (HTTP 404)', $this->log());
        $this->assertSame(str_repeat("\0", self::MIB), $this->emmc());
    }

    public function test_a_server_that_sends_the_whole_file_again_fails_at_once()
    {
        $this->serve(gzencode(self::diskImage(2 * self::MIB), 6));
        file_put_contents($this->dir.'/breaks', "5000\n");
        touch($this->dir.'/ignore-range');

        $this->assertNotSame(0, $this->write());

        $this->assertSame([0, 5000], $this->imageRequests());
        $this->assertStringContainsString('curl exit code 33', $this->log());
    }

    public function test_a_failing_decompressor_stops_the_download_at_once()
    {
        $deflate = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]);
        $valid = deflate_add($deflate, substr(self::diskImage(3 * self::MIB), 0, self::MIB + self::MIB / 2), ZLIB_FULL_FLUSH);
        // then a stored block whose length check fails: gzip gives up mid-stream while curl still has megabytes to send
        $this->serve($valid."\x00\x10\x00\x10\x00".str_repeat("\xaa", 2 * self::MIB));

        $this->assertNotSame(0, $this->write());

        $this->assertSame([0], $this->imageRequests(), 'no download after the decompressor gave up');
        $this->assertStringContainsString('curl exit code 23', $this->log());
        $this->assertSame(str_repeat("\0", self::MIB), substr($this->emmc(), 0, self::MIB));
    }

    public function test_a_file_shorter_than_the_script_was_told_fails_at_once()
    {
        $compressed = gzencode(self::diskImage(2 * self::MIB), 6);
        $this->serve($compressed);
        $size = strlen($compressed);

        $this->assertNotSame(0, $this->write(['image_bytes' => $size + 1000]));

        $this->assertSame([0, $size], $this->imageRequests(), 'one more request for the rest, not ten minutes of them');
        $this->assertStringContainsString("the image on the server ends at $size of ".($size + 1000).' bytes', $this->log());
    }

    public function test_byte_counts_past_two_gibibytes_stay_exact()
    {
        $this->serve('x');
        file_put_contents($this->dir.'/sum.sh', view('scriptexecute.write', ['image_url' => self::URL, 'image_bytes' => 1,
            'decompress' => 'gzip -dc', 'pieces' => null, 'resume' => null])->render()
            ."\nbytes_add 3000000000 3000000000\nbytes_at_least 6000000000 5999999999 && echo yes\nbytes_at_least 5999999999 6000000000 || echo no\n");

        exec('PATH='.escapeshellarg($this->dir.'/bin:'.getenv('PATH')).' '.escapeshellarg($this->busybox).' sh '.escapeshellarg($this->dir.'/sum.sh'), $out);

        $this->assertSame(['6000000000', 'yes', 'no'], $out);
    }

    /** The pieces copy of $image as SplitImage makes it, pieces of 1 MiB: [file contents, member starts and the size] */
    protected static function pieces(string $image): array
    {
        $members = [gzencode(substr($image, 0, self::MIB), 6)];
        for ($at = self::MIB; $at < strlen($image); $at += self::MIB)
            $members[] = gzencode(substr($image, $at, self::MIB), 6);
        $offsets = [0];
        foreach ($members as $member)
            $offsets[] = end($offsets) + strlen($member);
        return [implode('', $members), $offsets];
    }

    /** The durable marks the write sent, in MiB */
    protected function durableMarks(): array
    {
        return array_values(array_filter(array_map(function ($line) {
            return preg_match('/^mark write\|[^|]*\|\|(\d+)$/', $line, $m) ? (int) $m[1] : null;
        }, $this->lines('marks'))));
    }

    public function test_pieces_are_marked_durable_one_piece_behind()
    {
        $image = self::diskImage(4 * self::MIB + self::MIB / 2);   // the first MiB, pieces 0-2 and half a piece 3
        [$copy, $offsets] = self::pieces($image);
        $this->serve($copy);

        $this->assertSame(0, $this->write(['pieces' => ['count' => 4, 'mb' => 1]]));

        $this->assertSame($image, $this->emmc());
        $this->assertSame([2, 3, 4], $this->durableMarks(), 'piece k-1 once piece k is written; the last piece never');
        $this->assertSame('4', trim(file_get_contents($this->dir.'/work/durable')));
    }

    public function test_a_broken_connection_inside_a_piece_is_resumed_too()
    {
        $image = self::diskImage(4 * self::MIB + self::MIB / 2);
        [$copy, $offsets] = self::pieces($image);
        $this->serve($copy);
        file_put_contents($this->dir.'/breaks', ($offsets[2] + 100)."\n");

        $this->assertSame(0, $this->write(['pieces' => ['count' => 4, 'mb' => 1]]));

        $this->assertSame($image, $this->emmc());
        $this->assertSame([0, $offsets[2] + 100], $this->imageRequests());
        $this->assertSame([2, 3, 4], $this->durableMarks());
    }

    protected function resume(array $offsets, string $copy, ?string $head = null): array
    {
        return ['piece' => 2, 'from_byte' => $offsets[3],
            'head' => chunk_split(base64_encode($head ?? substr($copy, 0, $offsets[1])), 76, "\n"),
            'detail' => 'Resumed after a restart at 0.0 GB of 0.0 GB', 'base_sectors' => 3 * 2048];
    }

    public function test_a_resumed_run_writes_from_its_piece_and_the_partition_table_from_the_script()
    {
        $image = self::diskImage(4 * self::MIB + self::MIB / 2);
        [$copy, $offsets] = self::pieces($image);
        $this->serve($copy);
        // the earlier run wrote pieces 0 and 1 (the image from 1 to 3 MiB); after them the eMMC holds old data
        file_put_contents($this->dir.'/emmc', str_repeat("\0", self::MIB).substr($image, self::MIB, 2 * self::MIB)
            .str_repeat("\xee", strlen($image) - 3 * self::MIB));

        $this->assertSame(0, $this->write(['pieces' => ['count' => 4, 'mb' => 1], 'resume' => $this->resume($offsets, $copy)]));

        $this->assertSame($image, $this->emmc());
        $this->assertSame([$offsets[3]], $this->imageRequests(), 'from piece 2 on: the first MiB came with the script');
        $this->assertSame([], $this->lines('calls'), 'no blkdiscard');
        $marks = $this->lines('marks');
        $this->assertSame('start write|7|Resumed after a restart at 0.0 GB of 0.0 GB|6144', $marks[0]);
        $this->assertNotContains('mark write|Discarding old data||', $marks);
        $seeks = array_values(array_filter(array_map(function ($call) {
            return preg_match('/ seek=(\d+) /', $call, $m) ? (int) $m[1] : null;
        }, $this->lines('dd.calls'))));
        $this->assertSame([3, 4], $seeks, 'pieces 2 and 3 only');
        $this->assertSame([4], $this->durableMarks());
    }

    public function test_a_corrupt_piece_is_never_marked_durable()
    {
        $image = self::diskImage(4 * self::MIB + self::MIB / 2);
        [$copy, $offsets] = self::pieces($image);
        // the CRC of member 2 (piece 1) is wrong: the 8 bytes before the end of a member are its CRC32 and its length
        $at = $offsets[3] - 8;
        $copy[$at] = chr(ord($copy[$at]) ^ 0xff);
        $this->serve($copy);

        $this->assertNotSame(0, $this->write(['pieces' => ['count' => 4, 'mb' => 1]]));

        $this->assertSame([2], $this->durableMarks(), 'piece 0 is durable, the corrupt piece 1 never');
        $this->assertSame(str_repeat("\0", self::MIB), substr($this->emmc(), 0, self::MIB));
    }

    public function test_a_piece_the_storage_fails_to_write_ends_the_write_unmarked()
    {
        $image = self::diskImage(4 * self::MIB + self::MIB / 2);
        [$copy, $offsets] = self::pieces($image);
        $this->serve($copy);

        $this->assertNotSame(0, $this->write(['pieces' => ['count' => 4, 'mb' => 1]], ['FAKE_DD_FAIL' => 'seek=3']));   // piece 2

        $this->assertSame([2], $this->durableMarks(), 'piece 0 only: piece 1 counts once piece 2 is written');
        $this->assertStringContainsString('dd: fsync: Input/output error', $this->log());
        $this->assertSame(str_repeat("\0", self::MIB), substr($this->emmc(), 0, self::MIB));
    }

    public function test_a_stream_that_ends_between_pieces_is_not_taken_for_the_image()
    {
        $image = self::diskImage(4 * self::MIB + self::MIB / 2);
        [$copy, $offsets] = self::pieces($image);
        $this->serve(substr($copy, 0, $offsets[3]));   // members 0-2 only: gzip ends without complaint

        $this->assertNotSame(0, $this->write(['pieces' => ['count' => 4, 'mb' => 1]]));

        $this->assertSame(str_repeat("\0", self::MIB), substr($this->emmc(), 0, self::MIB), 'no partition table on a short image');
    }

    public function test_a_broken_partition_table_in_the_script_stops_before_the_download()
    {
        $image = self::diskImage(4 * self::MIB + self::MIB / 2);
        [$copy, $offsets] = self::pieces($image);
        $this->serve($copy);

        $this->assertNotSame(0, $this->write(['pieces' => ['count' => 4, 'mb' => 1],
            'resume' => $this->resume($offsets, $copy, gzencode(str_repeat("\0", 1000)))]));

        $this->assertSame([], $this->imageRequests());
        $this->assertStringContainsString('the first MiB in the script is broken', $this->log());
    }

    public function test_a_run_that_goes_on_over_a_storage_written_since_stops_before_the_download()
    {
        $image = self::diskImage(4 * self::MIB + self::MIB / 2);
        [$copy, $offsets] = self::pieces($image);
        $this->serve($copy);
        // flashed elsewhere after the write stopped: a partition table again, and other data
        $other = str_repeat("\x77", 512).str_repeat("\0", self::MIB - 512).str_repeat("\x99", strlen($image) - self::MIB);
        file_put_contents($this->dir.'/emmc', $other);

        $this->assertNotSame(0, $this->write(['pieces' => ['count' => 4, 'mb' => 1], 'resume' => $this->resume($offsets, $copy)]));

        $this->assertSame([], $this->imageRequests());
        $this->assertStringContainsString('the storage was written after the write stopped', $this->log());
        $this->assertSame($other, $this->emmc(), 'nothing written over it');
    }

    public function test_a_run_that_stopped_after_its_last_step_goes_on_over_its_own_partition_table()
    {
        $image = self::diskImage(4 * self::MIB + self::MIB / 2);
        [$copy, $offsets] = self::pieces($image);
        $this->serve($copy);
        // the write had ended, and the module restarted before the server heard of the next step
        file_put_contents($this->dir.'/emmc', $image);

        $this->assertSame(0, $this->write(['pieces' => ['count' => 4, 'mb' => 1], 'resume' => $this->resume($offsets, $copy)]));

        $this->assertSame($image, $this->emmc());
    }
}
