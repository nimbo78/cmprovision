# The image download resumes (2.6.1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A broken connection no longer fails the image write: the module resumes the download where it broke. The partition table is written last, so a module that loses power mid-write does not boot a half-written image. With the project checkbox "Split the image into 512 MB pieces" the server keeps a gzip copy of the image in pieces, and a module restarted mid-write (or after a failed write) continues from its last verified piece.

**Architecture:**
- The write step of the module's script moves into a partial, `resources/views/scriptexecute/write.blade.php`. `fetch_image` restarts only curl (`-C <bytes>`) while the decompressor and `dd` keep running. The first MiB goes to `$WORK/head` and is written last. With pieces, `write_pieces` writes 512 MiB at a time with `fsync` and marks a piece durable once the next one got data.
- `App\Jobs\SplitImage` (queue `images`) makes the pieces copy: member 0 is the first MiB of the image, each further gzip member one piece. The copy is one ordinary `.gz` with member offsets in `images.split_offsets`.
- `ScriptExecuteController::startProvisoning()` asks `Cm::resumableFrom()` before it resets the run. A run that can go on gets a script without the bootloader report, pre-install scripts and `blkdiscard`, with the first MiB inside it (base64), starting at the piece's offset.
- The pages: the project checkbox, the copy's state on Images, the Resume line and Start over on the module card, the advice and the restart dialogs.

**Tech Stack:** Laravel 13 (Laravel 8 layout), Livewire 3, SQLite, BusyBox 1.31.1 ash and curl 7.66.0 in the utility OS, bash with GNU coreutils and gzip on the server.

**Spec:** docs/superpowers/specs/2026-10-16-image-resume-design.md

## Conventions

```bash
SP=/c/Users/igors/AppData/Local/Temp/claude/c--Users-igors-OneDrive-----------Claude-cmprovision/61fffffd-cfb4-4c6d-b75a-efe1edfc6f5a/scratchpad
REPO="/c/Users/igors/OneDrive/Документы/Claude/cmprovision"
```

- Tests run on the WSL bench (PHP 8.4): `bash "$SP/wsl-bench/tests-ok.sh" '<filter>'` syncs the working tree and runs phpunit with that filter; its last line is `phpunit exit 0` when everything passed. The whole suite: `bash "$SP/wsl-bench/suite-ok.sh"` ends with `php rc=0 js rc=0`.
- Keep the bench awake while working (Task 0, step 1).
- Edits with the Edit tool; patch scripts, if any, are written with Write and run with `python -I`. One heredoc per command.
- Commit messages go to `$SP/commit-261-tN.txt` and in with `git commit -q -F`. Each ends with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Global Constraints

**Environment**
- PHP ≥ 8.3 (the bench runs 8.4), Laravel 13 with the Laravel 8 layout, Livewire 3. No new Composer or npm packages, no new Debian dependencies.
- The utility OS (checked in `scriptexecute/scriptexecute.img` on 2026-10-16): BusyBox v1.31.1 ash; `dd` knows `iflag=skip_bytes|fullblock`, `oflag=seek_bytes|append`, `conv=notrunc|noerror|sync|fsync` and prints only `N+M records in/out`, no byte count; curl 7.66.0 has `--keepalive-time`, `-C`, `-D`, `--retry-connrefused` and `-w` with `%{stderr}` and `%{size_download}`; `base64`, `wc`, `sed`, `awk`, `tail`, `tee`, `grep` are applets.
- The module's shell integers may be 32-bit: byte offsets and sums go through awk (`bytes_add`, `bytes_at_least`); `dd` seeks and the durable mark count MiB.
- The split job runs `bash` with GNU coreutils (`split --filter`, `dd`, `stat`, `wc`), `gzip`, `xz`, `bunzip2`, `sha256sum` — all in the base system.

**Texts, verbatim from the spec**
- Form: «Split the image into 512 MB pieces» (512 from `config('cmprovision.split_piece_mb')`), «A module restarted during the write continues from the last piece it wrote.»
- Step detail: «Resumed after a restart at 3.5 GB of 6.2 GB».
- Log lines: «Provisioning resumed: the module restarted during the write; continuing from 3.5 GB of 6.2 GB.», «Download resumed at 1.2 GB of 1.6 GB (the connection broke: curl exit 56).», «Start over: the next start writes the image from the beginning (by <name>).»
- Card: «Resume: the next start continues from 3.5 GB of 6.2 GB» (row title «Resume»), button «Start over».
- Module log (`/tmp/dd.log`): «download interrupted at <bytes> of <bytes> bytes (curl exit <code>), resuming», «no data from the provisioning server for 10 minutes (curl exit code <code>)», «curl exit code 22 (HTTP <code>)», «curl exit code 23», «curl exit code 33».
- Hints (`WRITE_FAILURE_HINTS`): «the provisioning server sent nothing for 10 minutes (network or server down)», «the server answered with an error: the image or its pieces were removed», «the server would not send the rest of the file».
- Copy states: «preparing…», «ready, 1.6 GB», «failed: <reason>», «not made yet». Job errors: «the image file changed since its SHA256 was computed», «not enough free space: need about X GB, Y GB free».

**Numbers**
- Piece: 512 MiB (`cmprovision.split_piece_mb`, tests 1). Head: 1 MiB (`ImagePieces::HEAD_BYTES`).
- `fetch_image`: 600 s without a byte (`FETCH_QUIET_SECONDS`), 5 s between attempts (`FETCH_PAUSE`), `--keepalive-time 15`, `--connect-timeout 10`.
- `SplitImage`: `$timeout` 7200 s (`ImagePieces::SPLIT_TIMEOUT`), free space ≥ original × 1.25 (`cmprovision.split_space_factor`), re-queued at most 3 times after a worker restart (`SplitImage::RESTARTS`), a minute later each time.

**Paths**
- The copy: `public/uploads/<40 random characters>.gz`. Work files of the job: `public/uploads/.split-<image id>.tmp` and `.tmp.index`.
- On the module: `$WORK` (default `/tmp`) holds `head`, `head.gz`, `fetch.hdr`, `fetch.err`, `piece.dd`, `durable`; `$WRITE_LOG` defaults to `/tmp/dd.log`.

**Views**
- Colours only through tokens (`TokenGuardTest`); fields use `cmp-field` (`FormFieldsTest`). The new markup uses classes the templates already have.

**Shell templates**
- Raw shell goes through `{!! !!}`; strings are quoted with `escapeshellarg()`.
- Blade directives stand alone on their lines, at column 0 (a directive at the end of a line eats the newline).
- Shell text has no `@` followed by a letter. Heredocs inside the script use the marker `CMPROVISIONINGEOF`.

**Version:** 2.6.1~dev1 until the release, 2.6.1 at the release.

## Review Focus

1. **Offsets past 2 GiB on the module.** The compressed file of a big image passes 2^31 bytes; the shell must not wrap the count. Task 2: `test_byte_counts_past_two_gibibytes_stay_exact`.
2. **A module that restarts again before it wrote a new piece.** The second resume starts from the same piece and still adds a step of its own, with a fresh clock. Task 5: `test_a_second_restart_before_a_new_piece_resumes_from_the_same_piece_again`.
3. **A resumed run of a project with the OS check.** After the write the OS check goes on as in any run. Task 5: `test_a_resumed_run_keeps_its_os_check`.
4. **An image with no SHA256 yet when the checkbox goes on** (API or form right after the upload). The copy job fills it in itself. Task 4: `test_the_copy_holds_the_first_mib_and_then_each_piece_as_a_gzip_member_of_its_own` (asserts the filled `uncompressed_sha256` and size).
5. **The decompressor dies while curl is still sending.** No retries into a closed pipe for 10 minutes. Task 2: `test_a_failing_decompressor_stops_the_download_at_once`.

## Plan rulings against the spec

These are the plan's own decisions where it departs from the spec's wording; each also goes into the ledger.

- **«Download resumed» goes to the module's log (History), not to the run's steps.** `Cm::setPhase()` starts a new step and restarts the phase clock and the byte counter on any detail change; the dashboard's speed and time left would be wrong after every resume. Cost if wrong: the resumes are a line in the History, not a step.
- **The first MiB of a resumed run comes inside the script** (base64 of gzip member 0, decoded by BusyBox `base64 -d`), not by `curl -r 0-<end>`. No extra request and no retry loop. Cost if wrong: an image whose first MiB holds data makes the resumed script up to ~1.4 MB larger.
- **The durable mark travels in MiB** (`durable_mb`) and byte sums on the module go through awk: the utility OS shell may count in 32 bits, and offsets of big images pass 2^31. Cost if wrong: none for the user.
- **curl counts the bytes** (`-w '%{stderr}FETCHED %{size_download}\n'`), not an inner `dd`: BusyBox `dd` in the utility OS prints no byte count. A failing decompressor shows as curl exit 23 (write error) and stops the download at once.
- **curl's progress meter leaves the module's console** (the spec keeps it): curl's stderr goes to `$WORK/fetch.err`, where the byte count is read, so curl runs with `-s -S`; its error messages are copied back to the console. Cost if wrong: someone watching the module's HDMI console sees no meter; the dashboard and the LEDs show the progress.
- **A piece counts only when the next piece got data** (`dd`'s `records in` line); otherwise a dead decompressor would let the loop mark empty pieces durable. The spec's rule «the CRC was checked before the next piece's first byte» stands; this makes it hold when the stream ends.
- **Two columns the spec does not name:** `images.split_queued_at` (for «preparing…», and a run older than the job's timeout counts as stopped) and `cms.progress_base` (the speed after a resume counts only the part written since).
- **The Resume line on the card shows only for a failed or silent module.** For a module that writes normally it would only confuse.
- **The Images buttons read «Remove pieces» and «Retry pieces»**, next to the image's own Delete.

---

## File Structure

**Created**

| File | Responsibility |
|---|---|
| `database/migrations/2026_10_16_100000_add_image_resume.php` | `projects.split_image`; `images.split_*`; `cms.durable_bytes`, `durable_image_id`, `progress_base` |
| `app/Support/ImagePieces.php` | the head, the piece size, the pieces arithmetic, «3.5 GB», the resumed step's detail |
| `app/Jobs/SplitImage.php` | the pieces copy of an image |
| `resources/views/scriptexecute/write.blade.php` | the module's write: `fetch_image`, `write_plain`, `write_pieces`, `write_image` |
| `tests/Feature/ImageResumeDataTest.php` | columns, arithmetic, the copy's helpers, the speed after a resume |
| `tests/Feature/ImageWriteScriptTest.php` | the write under BusyBox sh with a stand-in curl |
| `tests/Feature/ImageWriteServerTest.php` | the script uses the partial, the resume note, the hints |
| `tests/Feature/SplitImageTest.php` | the copy job |
| `tests/Feature/ImageResumeStartTest.php` | the decision at the start, the resumed run, the durable marks |
| `tests/Feature/ImagePiecesPagesTest.php` | the project form, the API, the Images page |
| `tests/Feature/ImageResumeTextsTest.php` | the card, the steps' speed, the advice, the restart dialogs |

**Modified**

| File | Change |
|---|---|
| `app/Models/Image.php` | the copy: path, offsets, ready, preparing, needed, state, queue, remove; `delete()` |
| `app/Models/Project.php` | `split_image` |
| `app/Models/Cm.php` | columns; `progress_base` in `setPhase()` and `bytesPerSecond()`; `resumableFrom()`, `stoppedInTheWrite()`, `resumeWrite()` |
| `config/cmprovision.php` | `split_piece_mb`, `split_space_factor` |
| `resources/views/scriptexecute.blade.php` | `progress_mark` 4th argument, `progress_start` detail and base, `progress_note`, the write through the partial, no bootloader report on a resumed run |
| `app/Http/Controllers/ScriptExecuteController.php` | hints; the resume note and durable marks in `registerProgress()`; `startProvisoning()` split into `moduleFields()`, `postinstallScripts()`, `writeData()`, `moduleScript()`; `resumeProvisioning()` |
| `app/Http/Livewire/Projects.php`, `editproject.blade.php`, `routes/api.php` | the checkbox |
| `app/Http/Livewire/Images.php`, `images.blade.php` | the copy's state, Remove pieces, Retry pieces, polling |
| `app/Http/Livewire/CmCard.php`, `cm-card.blade.php` | the Resume line, Start over |
| `app/Support/RunTimeline.php`, `app/Support/FailureAdvice.php` | the speed of a resumed step; «it continues the write from …» |
| `app/Services/Switch/PoeRestart.php`, `app/Http/Livewire/ProvisioningStatus.php`, `provisioning-status.blade.php` | the restart dialogs |
| `tests/Feature/ImageWriteDiagnosticsTest.php`, `tests/Feature/ProvisioningProgressTest.php` | the new write lines |
| `CLAUDE.md`, `README.md`, `ROADMAP.md`, `debian/changelog` | docs and version |

---

### Task 0: Bench and version

**Files:**
- Modify: `debian/changelog` (a new top entry)

- [ ] **Step 1: Keep the bench awake** (background task, Bash `run_in_background: true`):

```bash
MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sleep 14400
```

- [ ] **Step 2: Version 2.6.1~dev1.** Put this entry above `cmprovision4 (2.6.0)` in `debian/changelog`:

```
cmprovision4 (2.6.1~dev1) unstable; urgency=medium

  * Work in progress: the image download resumes after a broken
    connection or a restart
    (docs/superpowers/specs/2026-10-16-image-resume-design.md).

 -- nimbo78 <igor.sin.78@gmail.com>  Fri, 16 Oct 2026 10:00:00 +0300

```

- [ ] **Step 3: The suite is green before anything changes**

Run: `bash "$SP/wsl-bench/suite-ok.sh"`
Expected: `php rc=0 js rc=0`, 980 PHP tests (13 of them skipped), 54 JS tests.

- [ ] **Step 4: Commit**

`$SP/commit-261-t0.txt`:
```
2.6.1~dev1: the image download resume begins

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
```
```bash
cd "$REPO" && git add debian/changelog && git commit -q -F "$SP/commit-261-t0.txt" && git show --stat --format='%h %s' HEAD
```

---

### Task 1: Data — the columns, the pieces arithmetic, the copy's helpers

**Files:**
- Create: `database/migrations/2026_10_16_100000_add_image_resume.php`, `app/Support/ImagePieces.php`
- Modify: `app/Models/Image.php`, `app/Models/Project.php`, `app/Models/Cm.php`, `config/cmprovision.php`
- Test: `tests/Feature/ImageResumeDataTest.php`

**Interfaces:**
- Produces:
  - `projects.split_image` (bool, false); `images.split_filename` (string), `split_offsets` (JSON text), `split_piece_mb` (int), `split_error` (text), `split_queued_at` (timestamp); `cms.durable_bytes`, `cms.durable_image_id`, `cms.progress_base` (unsigned big ints) — all nullable.
  - `App\Support\ImagePieces`: `HEAD_BYTES = 1048576`, `SPLIT_TIMEOUT = 7200`, `pieceMb(): int`, `count(int $size, int $pieceMb): int`, `resumePiece(int $durable, int $pieceMb): int`, `pieceStart(int $k, int $pieceMb): int`, `gb(int $bytes): string`, `resumedDetail(int $from, int $total): string`.
  - `Image`: `splitPath(): ?string`, `splitOffsets(): array`, `splitReady(): bool`, `splitPreparing(): bool`, `splitNeeded(): bool`, `removeSplit(): void`; `delete()` removes the copy too.
  - `Cm::bytesPerSecond()` counts from `progress_base`; `setPhase()` clears it with the byte counter.

- [ ] **Step 1: Write the failing test** — `tests/Feature/ImageResumeDataTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Cm;
use App\Models\Image;
use App\Models\Project;
use App\Support\ImagePieces;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** 2.6.1: the columns of the image resume, the pieces arithmetic, the pieces copy of an image, and the speed of a write that
    went on from where it stopped */
class ImageResumeDataTest extends TestCase
{
    use RefreshDatabase;

    const MIB = 1048576;

    protected $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file)
            if ($file && is_file($file))
                unlink($file);
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function image(array $attributes = [])
    {
        $image = new Image;
        $image->filename = 'wlanpi-os.img.gz';
        $image->filename_extension = 'gz';
        $image->filename_on_server = 'resume-'.uniqid().'.gz';
        $image->sha256 = str_repeat('a', 64);
        foreach ($attributes as $key => $value)
            $image->$key = $value;
        $image->save();
        return $image;
    }

    protected function project(Image $image, bool $split)
    {
        return Project::create(['name' => 'p'.uniqid(), 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'image_id' => $image->id,
            'label_moment' => 'never', 'verify' => false, 'os_check' => false, 'split_image' => $split]);
    }

    public function test_the_new_columns_start_empty()
    {
        $image = $this->image();
        $project = Project::create(['name' => '78', 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'image_id' => $image->id,
            'label_moment' => 'never', 'verify' => false, 'os_check' => false]);
        $cm = Cm::create(['serial' => '10000000e611110c', 'mac' => 'e4:5f:01:62:9f:d3']);

        $this->assertFalse($project->fresh()->split_image);
        $image = $image->fresh();
        $this->assertSame([null, null, null, null, null],
            [$image->split_filename, $image->split_offsets, $image->split_piece_mb, $image->split_error, $image->split_queued_at]);
        $cm = $cm->fresh();
        $this->assertSame([null, null, null], [$cm->durable_bytes, $cm->durable_image_id, $cm->progress_base]);
    }

    public function test_the_pieces_arithmetic()
    {
        $this->assertSame(512, ImagePieces::pieceMb());
        $this->assertSame(0, ImagePieces::count(self::MIB, 1), 'the first MiB alone has no pieces');
        $this->assertSame(1, ImagePieces::count(self::MIB + 1, 1));
        $this->assertSame(4, ImagePieces::count(4 * self::MIB + self::MIB / 2, 1));
        $this->assertSame(12, ImagePieces::count(6200000000, 512));
        $this->assertSame(0, ImagePieces::resumePiece(100, 1));
        $this->assertSame(1, ImagePieces::resumePiece(2 * self::MIB + 10, 1));
        $this->assertSame(2, ImagePieces::resumePiece(3 * self::MIB, 1));
        $this->assertSame(self::MIB + 2 * 512 * self::MIB, ImagePieces::pieceStart(2, 512));
        $this->assertSame('3.5 GB', ImagePieces::gb(3500000000));
        $this->assertSame('Resumed after a restart at 3.5 GB of 6.2 GB', ImagePieces::resumedDetail(3500000000, 6200000000));
    }

    public function test_a_copy_is_ready_only_with_its_offsets_and_its_file()
    {
        $image = $this->image(['split_filename' => 'pieces-'.uniqid().'.gz', 'split_offsets' => json_encode([0, 40, 90, 120]),
            'split_piece_mb' => 1]);
        $this->files[] = $image->splitPath();
        $this->assertFalse($image->splitReady(), 'no file');

        file_put_contents($image->splitPath(), str_repeat('x', 120));
        $this->assertTrue($image->splitReady());
        $this->assertSame([0, 40, 90, 120], $image->splitOffsets());

        $image->split_offsets = json_encode([0, 120]);
        $this->assertFalse($image->splitReady(), 'not even one piece');
    }

    public function test_a_copy_on_its_way_counts_only_within_the_job_timeout()
    {
        Carbon::setTestNow('2026-10-16 10:00:00');
        $image = $this->image(['split_queued_at' => now()->subSeconds(ImagePieces::SPLIT_TIMEOUT - 60)]);
        $this->assertTrue($image->splitPreparing());

        $image->split_queued_at = now()->subSeconds(ImagePieces::SPLIT_TIMEOUT + 60);
        $this->assertFalse($image->splitPreparing());
    }

    public function test_removing_the_pieces_or_the_image_removes_the_copy()
    {
        $image = $this->image(['split_filename' => 'pieces-'.uniqid().'.gz', 'split_offsets' => json_encode([0, 4, 8]),
            'split_piece_mb' => 1]);
        $this->files[] = $copy = $image->splitPath();
        file_put_contents($copy, 'xxxxxxxx');

        $image->removeSplit();

        $this->assertFileDoesNotExist($copy);
        $image = $image->fresh();
        $this->assertSame([null, null, null], [$image->split_filename, $image->split_offsets, $image->split_piece_mb]);

        $image->split_filename = 'pieces-'.uniqid().'.gz';
        $image->save();
        $this->files[] = $copy = $image->splitPath();
        file_put_contents($copy, 'x');

        $image->delete();

        $this->assertFileDoesNotExist($copy);
    }

    public function test_a_project_needs_the_copy_of_its_image_only_with_the_checkbox()
    {
        $image = $this->image();
        $this->project($image, false);
        $this->assertFalse($image->splitNeeded());

        $this->project($image, true);
        $this->assertTrue($image->splitNeeded());
    }

    public function test_a_write_that_went_on_counts_its_speed_from_where_it_went_on()
    {
        Carbon::setTestNow('2026-10-16 10:00:00');
        $cm = new Cm(['serial' => '10000000e611110c', 'phase' => 'write', 'progress_total' => 6000000000,
            'progress_base' => 3000000000, 'progress_bytes' => 4000000000,
            'phase_started_at' => now()->subSeconds(100), 'progress_updated_at' => now()]);

        $this->assertSame(66, $cm->progressPercent(), 'of the whole image');
        $this->assertEqualsWithDelta(10000000, $cm->bytesPerSecond(), 0.001, 'only what this run wrote');
        $this->assertSame(200, $cm->secondsLeft());
    }

    public function test_entering_a_phase_forgets_where_the_write_went_on_from()
    {
        $cm = new Cm(['serial' => '10000000e611110c', 'phase' => 'write', 'progress_base' => 3000000000, 'progress_bytes' => 4000000000]);

        $cm->setPhase('verify');

        $this->assertSame([null, null], [$cm->progress_base, $cm->progress_bytes]);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `bash "$SP/wsl-bench/tests-ok.sh" 'ImageResumeDataTest'`
Expected: FAIL — `Class "App\Support\ImagePieces" not found` and `no such column: split_image`.

- [ ] **Step 3: The migration** — `database/migrations/2026_10_16_100000_add_image_resume.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* 2.6.1, the image resume: the project's checkbox for the pieces copy of its image, the copy itself (App\Jobs\SplitImage),
   and how far each module's run on the copy is written for sure */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('split_image')->default(false);
        });
        Schema::table('images', function (Blueprint $table) {
            $table->string('split_filename')->nullable();
            $table->text('split_offsets')->nullable();
            $table->unsignedInteger('split_piece_mb')->nullable();
            $table->text('split_error')->nullable();
            $table->timestamp('split_queued_at')->nullable();
        });
        Schema::table('cms', function (Blueprint $table) {
            $table->unsignedBigInteger('durable_bytes')->nullable();
            $table->unsignedBigInteger('durable_image_id')->nullable();
            $table->unsignedBigInteger('progress_base')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cms', function (Blueprint $table) {
            $table->dropColumn(['durable_bytes', 'durable_image_id', 'progress_base']);
        });
        Schema::table('images', function (Blueprint $table) {
            $table->dropColumn(['split_filename', 'split_offsets', 'split_piece_mb', 'split_error', 'split_queued_at']);
        });
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('split_image');
        });
    }
};
```

- [ ] **Step 4: The pieces arithmetic** — `app/Support/ImagePieces.php`:

```php
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
```

- [ ] **Step 5: The config** — in `config/cmprovision.php`, before the closing `];`, after the `telegram_api` entry:

```php
    /*
    |--------------------------------------------------------------------------
    | Image pieces (2.6.1)
    |--------------------------------------------------------------------------
    |
    | A project with "Split the image into pieces" keeps a gzip copy of its image in pieces of this many MiB; a
    | module restarted mid-write continues from its last verified piece. Making the copy needs free space of
    | about the original's size times split_space_factor.
    |
    */

    'split_piece_mb' => (int) env('CMPROVISION_SPLIT_PIECE_MB', 512),

    'split_space_factor' => 1.25,
```

- [ ] **Step 6: The project** — in `app/Models/Project.php`:

```php
    protected $fillable = ['name','device','storage','image_id','label_id','label_moment','eeprom_firmware','eeprom_settings','verify','os_check','split_image'];
    protected $casts = ['verify' => 'boolean', 'os_check' => 'boolean', 'split_image' => 'boolean'];
```

- [ ] **Step 7: The image** — replace `app/Models/Image.php` with:

```php
<?php

namespace App\Models;

use App\Support\ImagePieces;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Image extends Model
{
    use HasFactory;

    protected $casts = ['uncompressed_size' => 'integer', 'split_piece_mb' => 'integer', 'split_queued_at' => 'datetime'];

    function imagepath()
    {
        return public_path('uploads/'.$this->filename_on_server);
    }

    /* Size of the compressed file on disk, or null when the file is gone */
    function filesize()
    {
        return is_file($this->imagepath()) ? filesize($this->imagepath()) : null;
    }

    /* The pieces copy (2.6.1, App\Support\ImagePieces): its file, null without one */
    function splitPath()
    {
        return $this->split_filename ? public_path('uploads/'.$this->split_filename) : null;
    }

    /* Where every gzip member starts in the copy, and last the copy's size */
    function splitOffsets(): array
    {
        $offsets = json_decode((string) $this->split_offsets, true);
        return is_array($offsets) ? array_map('intval', $offsets) : [];
    }

    /* The copy is there: in the database, with at least one piece, and on disk */
    function splitReady(): bool
    {
        return $this->split_filename && count($this->splitOffsets()) >= 3 && is_file($this->splitPath());
    }

    /* A job makes the copy; one queued longer ago than the job's timeout has stopped without a result */
    function splitPreparing(): bool
    {
        return $this->split_queued_at !== null
            && $this->split_queued_at->getTimestamp() > now()->getTimestamp() - ImagePieces::SPLIT_TIMEOUT;
    }

    /* A project with "Split the image into pieces" writes this image */
    function splitNeeded(): bool
    {
        return Project::where('image_id', $this->id)->where('split_image', true)->exists();
    }

    function removeSplit()
    {
        if ($this->splitPath() && is_file($this->splitPath()))
            unlink($this->splitPath());
        $this->split_filename = null;
        $this->split_offsets = null;
        $this->split_piece_mb = null;
        $this->save();
    }

    function delete()
    {
        // Delete image from filesystem, if it is still there
        if (is_file($this->imagepath()))
            unlink($this->imagepath());
        // and its pieces copy
        if ($this->splitPath() && is_file($this->splitPath()))
            unlink($this->splitPath());

        // Delete from database
        parent::delete();
    }
}
```

- [ ] **Step 8: The module** — in `app/Models/Cm.php`:
  - append `'durable_bytes', 'durable_image_id', 'progress_base',` to `$fillable` (after `'os_check', 'hostname', …` line);
  - add to `$casts`: `'durable_bytes' => 'integer', 'durable_image_id' => 'integer', 'progress_base' => 'integer',`;
  - in `setPhase()`, replace `if (in_array($phase, self::ACTIVE_PHASES, true)) $this->progress_bytes = null;` with:

```php
            if (in_array($phase, self::ACTIVE_PHASES, true))
            {
                $this->progress_bytes = null;
                $this->progress_base = null;
            }
```
  - replace `bytesPerSecond()` with:

```php
    /* Average bytes per second since the phase started, null until there is something to average; a write that went on
       after a restart (2.6.1) counts from where it went on (progress_base) */
    public function bytesPerSecond()
    {
        if (!$this->isStreaming() || !$this->progress_bytes || !$this->phase_started_at || !$this->progress_updated_at)
            return null;
        $seconds = $this->progress_updated_at->getTimestamp() - $this->phase_started_at->getTimestamp();
        $written = $this->progress_bytes - (int) $this->progress_base;
        return $seconds > 0 && $written > 0 ? $written / $seconds : null;
    }
```

- [ ] **Step 9: Run the test to verify it passes**

Run: `bash "$SP/wsl-bench/tests-ok.sh" 'ImageResumeDataTest|ProvisioningProgressTest|ModuleCardTest'`
Expected: `phpunit exit 0`.

- [ ] **Step 10: Commit**

`$SP/commit-261-t1.txt`:
```
Data for the image resume: the project checkbox, the pieces copy, the durable marks

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
```
```bash
cd "$REPO" && git add database/migrations/2026_10_16_100000_add_image_resume.php app/Support/ImagePieces.php app/Models/Image.php app/Models/Project.php app/Models/Cm.php config/cmprovision.php tests/Feature/ImageResumeDataTest.php && git commit -q -F "$SP/commit-261-t1.txt" && git show --stat --format='%h %s' HEAD
```

---

### Task 2: The write on the module — the download resumes, the partition table goes last (all projects)

**Files:**
- Create: `resources/views/scriptexecute/write.blade.php`
- Modify: `resources/views/scriptexecute.blade.php` (the progress functions, `progress_note`, the write block), `app/Http/Controllers/ScriptExecuteController.php` (`WRITE_FAILURE_HINTS`, `registerProgress()`, the view data)
- Modify tests: `tests/Feature/ImageWriteDiagnosticsTest.php`, `tests/Feature/ProvisioningProgressTest.php`
- Test: `tests/Feature/ImageWriteScriptTest.php`, `tests/Feature/ImageWriteServerTest.php`

**Interfaces:**
- Consumes: `ImagePieces::gb()` (Task 1).
- Produces:
  - The partial `scriptexecute.write` takes `$image_url`, `$image_bytes` (size of the file the module downloads), `$decompress`, `$pieces` (null here), `$resume` (null here). It defines `bytes_add`, `bytes_at_least`, `fetch_image <from byte>`, `write_plain`, `write_image`, and the variables `IMAGE_URL`, `IMAGE_BYTES`, `WORK`, `WRITE_LOG`, `FETCH_QUIET_SECONDS`, `FETCH_PAUSE`, `PROGRESS_DETAIL`, `PROGRESS_BASE_SECTORS`.
  - In the module script: `progress_mark <phase> <detail> <sectors> <durable MiB>`, `progress_start <phase> <field> [<detail> [<sectors before>]]`, `progress_note <bytes> <of bytes> <curl exit code>`.
  - The server: `?progress=write&resumed=<bytes>&of=<bytes>&curl=<code>` writes a log line and changes nothing else.

- [ ] **Step 1: Write the failing shell test** — `tests/Feature/ImageWriteScriptTest.php` (Task 3 adds more tests to this class):

```php
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
    const APPLETS = ['gzip', 'xz', 'bunzip2', 'base64', 'sed', 'awk', 'wc', 'tail', 'tee', 'grep', 'cat'];

    /* curl as the module sees it, serving $FAKE/www: -C, -D, -f and -w as curl does them. What goes wrong comes from
         $FAKE/breaks        one number per request: only that many bytes, then the connection breaks (exit 56)
         $FAKE/statuses      one HTTP status per request (4xx/5xx: exit 22, nothing delivered)
         $FAKE/ignore-range  a ranged request gets the whole file (curl gives up: exit 33) */
    const FAKE_CURL = <<<'SH'
echo "$*" >> "$FAKE/curl.log"
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
        // dd as in the utility OS, every call noted
        $this->stub('dd', 'echo "$*" >> "$FAKE/dd.calls"; exec '.escapeshellarg($this->busybox).' dd "$@"');
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
        $compressed = gzencode(self::diskImage(3 * self::MIB), 6);
        $this->serve(substr($compressed, 0, 2000).str_repeat("\xff", 4000).substr($compressed, 6000));

        $this->assertNotSame(0, $this->write());

        $this->assertSame([0], $this->imageRequests(), 'no download after the decompressor gave up');
        $this->assertStringContainsString('curl exit code 23', $this->log());
        $this->assertSame(str_repeat("\0", self::MIB), substr($this->emmc(), 0, self::MIB));
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
}
```

- [ ] **Step 2: Write the failing server test** — `tests/Feature/ImageWriteServerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Http\Controllers\ScriptExecuteController;
use App\Models\Cm;
use App\Models\Cmlog;
use App\Models\Image;
use App\Models\Project;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** The server's side of the resuming download (2.6.1): the module's script writes through write_image, a resumed download
    is a line in the module's log, and the new ways a write fails are explained */
class ImageWriteServerTest extends TestCase
{
    use RefreshDatabase;

    const SERIAL = '10000000e611110c';
    const START = '/scriptexecute?serial='.self::SERIAL.'&model=CM4&storagesize=62500000&mac=e4:5f:01:62:9f:d3';

    protected $file;

    protected function tearDown(): void
    {
        if ($this->file && is_file($this->file))
            unlink($this->file);
        parent::tearDown();
    }

    protected function project()
    {
        $image = new Image;
        $image->filename = 'wlanpi-os.img.gz';
        $image->filename_extension = 'gz';
        $image->filename_on_server = 'write-'.uniqid().'.gz';
        $image->sha256 = str_repeat('a', 64);
        $image->uncompressed_size = 8 * 1048576;
        $image->save();
        $this->file = $image->imagepath();
        file_put_contents($this->file, str_repeat('z', 12345));
        $project = Project::create(['name' => '78', 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'image_id' => $image->id,
            'label_moment' => 'never', 'verify' => false, 'os_check' => false]);
        Setting::updateOrCreate(['key' => 'active_project'], ['value' => $project->id]);
        return $project;
    }

    public function test_the_module_writes_through_write_image_with_the_size_of_its_download()
    {
        $this->project();

        $script = $this->get(self::START)->assertOk()->getContent();

        $this->assertStringContainsString("IMAGE_BYTES=12345\n", $script);
        $this->assertStringContainsString("\nwrite_image\nRETCODE=\$?\n", $script);
        $this->assertStringContainsString('fetch_image 0 | { gzip -dc 2>>"$WRITE_LOG"; } | write_plain', $script);
        $this->assertStringContainsString('progress_note() {', $script);
        $this->assertStringNotContainsString('curl --retry 10 -g "', $script);
    }

    public function test_a_resumed_download_is_a_line_in_the_module_log_and_leaves_the_step_alone()
    {
        $this->project();
        $this->get(self::START)->assertOk();
        $this->get('/scriptexecute?serial='.self::SERIAL.'&progress=write&detail=&sectors=2048')->assertOk();
        $timeline = Cm::where('serial', self::SERIAL)->first()->timeline;

        $this->get('/scriptexecute?serial='.self::SERIAL.'&progress=write&resumed=1200000000&of=1600000000&curl=56')->assertOk();

        $cm = Cm::where('serial', self::SERIAL)->first();
        $this->assertSame($timeline, $cm->timeline);
        $this->assertSame(2048 * 512, $cm->progress_bytes);
        $this->assertTrue(Cmlog::where('cm', self::SERIAL)
            ->where('msg', 'Download resumed at 1.2 GB of 1.6 GB (the connection broke: curl exit 56).')->exists());
    }

    public static function hints(): array
    {
        return [
            'ten quiet minutes' => ["no data from the provisioning server for 10 minutes (curl exit code 7)\n",
                'the provisioning server sent nothing for 10 minutes (network or server down)'],
            'a file gone' => ["curl exit code 22 (HTTP 404)\n", 'the server answered with an error: the image or its pieces were removed'],
            'no range' => ["curl exit code 33\n", 'the server would not send the rest of the file'],
        ];
    }

    #[DataProvider('hints')]
    public function test_the_ways_the_resuming_download_fails_are_explained($log, $hint)
    {
        $this->assertSame($hint, ScriptExecuteController::diagnoseWriteFailure($log));
    }
}
```

- [ ] **Step 3: Run both to verify they fail**

Run: `bash "$SP/wsl-bench/tests-ok.sh" 'ImageWriteScriptTest|ImageWriteServerTest'`
Expected: FAIL — `View [scriptexecute.write] not found`, `IMAGE_BYTES` missing from the script, the hints `null`.

- [ ] **Step 4: The partial** — `resources/views/scriptexecute/write.blade.php`:

```blade
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
                bytes_at_least "$got" "$IMAGE_BYTES" && return 0 ;;
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

# write_image: the whole write; 0 once the image and, last, its partition table are on the storage
write_image() {
    : >"$WRITE_LOG"
    progress_mark write 'Discarding old data'
    echo Sending BLKDISCARD to $STORAGE
    blkdiscard -v $STORAGE || true
    # TRIM does not promise zeros on eMMC: the first MiB is cleared on purpose, and written last
    dd if=/dev/zero of="$STORAGE" bs=1M count=1 conv=fsync,notrunc 2>>"$WRITE_LOG" || return 1
    echo Writing image from $IMAGE_URL to $STORAGE
    progress_start write 7 "$PROGRESS_DETAIL" "$PROGRESS_BASE_SECTORS"
    fetch_image 0 | { {!! $decompress !!} 2>>"$WRITE_LOG"; } | write_plain
    status=$?
    progress_stop
    [ $status -eq 0 ] || return $status
    dd if="$WORK/head" of="$STORAGE" bs=1M count=1 conv=fsync,notrunc 2>>"$WRITE_LOG"
}
```

- [ ] **Step 5: The module script** — in `resources/views/scriptexecute.blade.php`:

Replace `progress_mark()` and `progress_start()` (lines 26-40) with:

```sh
progress_mark() {
    curl --silent --output /dev/null --connect-timeout 2 --max-time 4 -G "http://{{ $server }}/scriptexecute" \
        --data-urlencode "serial={{ $cm->serial }}" --data-urlencode "progress=$1" \
        --data-urlencode "detail=$2" --data-urlencode "sectors=$3" --data-urlencode "durable_mb=$4" || true
}
# progress_start <phase> <field of $STORAGE_STAT: 7 = sectors written, 3 = sectors read> [<detail> [<sectors before>]]:
# a write that goes on after a restart keeps its step (detail) and counts on from where it was; the marks also carry how
# far the write is durable (write_pieces in scriptexecute/write.blade.php)
progress_start() {
    progress_mark "$1" "$3"
    [ -r "$STORAGE_STAT" ] || return 0
    PROGRESS_BASE=$(awk -v f="$2" '{print $f}' "$STORAGE_STAT")
    ( while sleep 5; do
          progress_mark "$1" "$3" "$(( ${4:-0} + $(awk -v f="$2" '{print $f}' "$STORAGE_STAT") - PROGRESS_BASE ))" \
              "$(cat "${WORK:-/tmp}/durable" 2>/dev/null)"
      done ) &
    PROGRESS_PID=$!
}
# progress_note <bytes> <of bytes> <curl exit code>: the download broke and goes on from that byte (a line in the log)
progress_note() {
    curl --silent --output /dev/null --connect-timeout 2 --max-time 4 -G "http://{{ $server }}/scriptexecute" \
        --data-urlencode "serial={{ $cm->serial }}" --data-urlencode "progress=write" \
        --data-urlencode "resumed=$1" --data-urlencode "of=$2" --data-urlencode "curl=$3" || true
}
```

Replace the write block, from `@if ($image_url)` (line 130) through its `@endif` before `@if ( count($postinstall_scripts) || $os_check )`, with:

```blade
@if ($image_url)
@include('scriptexecute.write')
write_image
RETCODE=$?
if [ $RETCODE -eq 0 ]; then
    echo Original image written successfully
else
    echo Writing image failed.
    # The partition table goes last, so a failed write leaves none: on the next power cycle the module returns to network
    # boot (provisioning). Cleared again in case the write failed after it.
    dd if=/dev/zero of=$STORAGE bs=1M count=1 conv=fsync,notrunc 2>/dev/null || true
    report -F 'log=@/tmp/dd.log' "http://{{ $server }}/scriptexecute?serial={{ $cm->serial }}&retcode=$RETCODE&phase=dd"
    finish fail
fi

partprobe $STORAGE
sleep 0.1
@endif
```

- [ ] **Step 6: The server** — in `app/Http/Controllers/ScriptExecuteController.php`:

Add `use App\Support\ImagePieces;` to the imports. Put these three entries first in `WRITE_FAILURE_HINTS` (the first matching hint wins, and the 10-minute line also names a curl code):

```php
        '/no data from the provisioning server for \d+ minutes/' => 'the provisioning server sent nothing for 10 minutes (network or server down)',
        '/curl exit code 22\b/' => 'the server answered with an error: the image or its pieces were removed',
        '/curl exit code 33\b/' => 'the server would not send the rest of the file',
```

In `registerProgress()`, right after `if ($this->cm->phase === 'firstboot') return '';`, insert:

```php
        /* the download broke and goes on (fetch_image in scriptexecute/write.blade.php): a line in the module's log; the
           step stays as it is, a new one would restart the phase clock and the speed would be wrong */
        $resumed = $req->query('resumed');
        if (is_string($resumed) && ctype_digit($resumed))
        {
            $of = $req->query('of');
            $code = $req->query('curl');
            $this->logInfo('Download resumed at '.ImagePieces::gb((int) $resumed)
                .(is_string($of) && ctype_digit($of) && (int) $of > 0 ? ' of '.ImagePieces::gb((int) $of) : '')
                .(is_string($code) && ctype_digit($code) ? ' (the connection broke: curl exit '.$code.').' : '.'));
            return '';
        }
```

In `startProvisoning()`, add to the array of `response()->view('scriptexecute', [...])`, after `'decompress' => …,`:

```php
            'image_bytes' => $image ? (int) $image->filesize() : null,
            'pieces' => null,
            'resume' => null,
```

- [ ] **Step 7: The existing script tests follow the new lines.** In `tests/Feature/ImageWriteDiagnosticsTest.php`, replace the four assertions of `test_script_records_curl_exit_code_and_decompressor_errors_in_the_write_log` with:

```php
        $this->assertStringContainsString('WRITE_LOG=${WRITE_LOG:-/tmp/dd.log}', $script);
        $this->assertStringContainsString(': >"$WRITE_LOG"', $script);
        $this->assertStringContainsString('echo "curl exit code $rc" >>"$WRITE_LOG"', $script);
        $this->assertStringContainsString('{ gzip -dc 2>>"$WRITE_LOG"; }', $script);
        $this->assertStringContainsString('dd of="$STORAGE" bs=1M seek=1 iflag=fullblock conv=fsync,notrunc 2>>"$WRITE_LOG"', $script);
```

and in `test_script_uses_the_decompressor_matching_the_image_type`:

```php
        $this->assertStringContainsString('{ xz -dc 2>>"$WRITE_LOG"; }', $this->provisioningScript('xz'));
```

In `tests/Feature/ProvisioningProgressTest.php`, `test_the_module_script_reports_phases_and_counts_sectors_while_writing_and_verifying`, replace the `progress_start write 7` assertion with:

```php
        $this->assertMatchesRegularExpression('/progress_start write 7 "\$PROGRESS_DETAIL" "\$PROGRESS_BASE_SECTORS"\n.*\| write_plain/s', $script);
```

- [ ] **Step 8: Run the tests to verify they pass**

Run: `bash "$SP/wsl-bench/tests-ok.sh" 'ImageWriteScriptTest|ImageWriteServerTest|ImageWriteDiagnosticsTest|ProvisioningProgressTest|OsCheckModuleScriptTest|EepromTransparencyTest|StandNetworkTest'`
Expected: `phpunit exit 0`; `ImageWriteScriptTest` runs (busybox is on the bench), none of its tests skipped.

- [ ] **Step 9: Commit**

`$SP/commit-261-t2.txt`:
```
A broken image download resumes where it broke, and the partition table goes last

curl starts again from the byte it got to while the decompressor and dd keep running, so a
switch hiccup or an nginx restart no longer fails the write. The first MiB is held back and
written last: a module that loses power mid-write finds no partition table and boots from
the network again instead of a half-written image.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
```
```bash
cd "$REPO" && git add resources/views/scriptexecute/write.blade.php resources/views/scriptexecute.blade.php app/Http/Controllers/ScriptExecuteController.php tests/Feature/ImageWriteScriptTest.php tests/Feature/ImageWriteServerTest.php tests/Feature/ImageWriteDiagnosticsTest.php tests/Feature/ProvisioningProgressTest.php && git commit -q -F "$SP/commit-261-t2.txt" && git show --stat --format='%h %s' HEAD
```

---

### Task 3: The write in pieces and the write that goes on (module)

**Files:**
- Modify: `resources/views/scriptexecute/write.blade.php`
- Test: `tests/Feature/ImageWriteScriptTest.php` (more tests)

**Interfaces:**
- Consumes: the partial and its harness (Task 2).
- Produces:
  - `$pieces = ['count' => <pieces after the first MiB>, 'mb' => <piece size>]` turns on `write_pieces`; it writes `$WORK/durable` (MiB) and marks it with `progress_mark write "$PROGRESS_DETAIL" "" <MiB>`.
  - `$resume = ['piece' => k, 'from_byte' => <offset of gzip member k+1>, 'head' => <base64 of member 0, lines of 76>, 'detail' => <step detail>, 'base_sectors' => <bytes before / 512>]` (with `$pieces`) runs the write that goes on.

- [ ] **Step 1: Write the failing tests** — add to `ImageWriteScriptTest`:

```php
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

    protected function resume(array $offsets, string $copy, string $head = null): array
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
```

- [ ] **Step 2: Run them to verify they fail**

Run: `bash "$SP/wsl-bench/tests-ok.sh" 'ImageWriteScriptTest'`
Expected: FAIL — the pieces tests get the plain write (no durable marks), the resume tests download from 0 and call blkdiscard.

- [ ] **Step 3: The pieces** — in `resources/views/scriptexecute/write.blade.php`, insert after `write_plain()`:

```blade
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
@endif
```

- [ ] **Step 4: The write that goes on** — replace `write_image()` in the partial with:

```blade
# write_image: the whole write; 0 once the image and, last, its partition table are on the storage
write_image() {
    : >"$WRITE_LOG"
@if (!$resume)
    progress_mark write 'Discarding old data'
    echo Sending BLKDISCARD to $STORAGE
    blkdiscard -v $STORAGE || true
@endif
    # TRIM does not promise zeros on eMMC: the first MiB is cleared on purpose, and written last
    dd if=/dev/zero of="$STORAGE" bs=1M count=1 conv=fsync,notrunc 2>>"$WRITE_LOG" || return 1
@if ($resume)
    write_head_from_script || { echo "the first MiB in the script is broken" >>"$WRITE_LOG"; return 1; }
@endif
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
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `bash "$SP/wsl-bench/tests-ok.sh" 'ImageWriteScriptTest|ImageWriteServerTest|ImageWriteDiagnosticsTest|ProvisioningProgressTest'`
Expected: `phpunit exit 0`.

- [ ] **Step 6: Commit**

`$SP/commit-261-t3.txt`:
```
The module writes a pieces copy piece by piece and can go on from a piece

Each piece is made durable with fsync before the next; it counts once the next piece got
data, after the decompressor checked its CRC, so a bad or missing piece is never marked.
A run that goes on gets the first MiB inside its script and downloads only from its piece.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
```
```bash
cd "$REPO" && git add resources/views/scriptexecute/write.blade.php tests/Feature/ImageWriteScriptTest.php && git commit -q -F "$SP/commit-261-t3.txt" && git show --stat --format='%h %s' HEAD
```

---

### Task 4: The pieces copy of an image (`SplitImage`)

**Files:**
- Create: `app/Jobs/SplitImage.php`
- Modify: `app/Models/Image.php` (`queueSplit()`)
- Test: `tests/Feature/SplitImageTest.php`

**Interfaces:**
- Consumes: `ImagePieces` and the `Image` helpers (Task 1).
- Produces:
  - `App\Jobs\SplitImage(Image $image, int $restarts = 0)` on the `images` queue; `SplitImage::RESTARTS = 3`; `SplitImage::temp(Image): string`.
  - `Image::queueSplit(): void` — queues the job unless the copy is ready or on its way, and sets `split_queued_at`.

- [ ] **Step 1: Write the failing test** — `tests/Feature/SplitImageTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Jobs\SplitImage;
use App\Models\Image;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** The pieces copy of an image (2.6.1): the first MiB and then each piece as a gzip member of its own, checked against the
    image before it counts; the original stays as it is */
class SplitImageTest extends TestCase
{
    use RefreshDatabase;

    const MIB = 1048576;

    protected $images = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['bash', 'split', 'stat', 'gzip', 'xz'] as $tool)
            if (trim((string) shell_exec('command -v '.$tool.' 2>/dev/null')) === '')
                $this->markTestSkipped('needs '.$tool);
        config(['cmprovision.split_piece_mb' => 1]);
    }

    protected function tearDown(): void
    {
        foreach ($this->images as $image)
        {
            $image = Image::find($image->id) ?: $image;
            foreach ([$image->imagepath(), $image->splitPath(), SplitImage::temp($image), SplitImage::temp($image).'.index'] as $file)
                if ($file && is_file($file))
                    unlink($file);
        }
        parent::tearDown();
    }

    protected static function diskImage(int $size): string
    {
        $data = str_repeat("\x55", 512).str_repeat("\0", self::MIB - 512);
        for ($i = 0; strlen($data) < $size; $i++)
            $data .= $i % 64 < 48 ? hash('sha256', 'block '.$i, true) : str_repeat("\0", 32);
        return substr($data, 0, $size);
    }

    protected function image(string $data, string $kind = 'gz', array $attributes = [])
    {
        $image = new Image;
        $image->filename = 'os.img.'.$kind;
        $image->filename_extension = $kind;
        $image->filename_on_server = 'split-'.uniqid().'.'.$kind;
        $image->sha256 = str_repeat('a', 64);
        foreach ($attributes as $key => $value)
            $image->$key = $value;
        $image->save();
        $this->images[] = $image;
        if ($kind === 'gz')
            file_put_contents($image->imagepath(), gzencode($data, 6));
        else
        {
            file_put_contents($image->imagepath().'.raw', $data);
            exec('/usr/bin/xz -c '.escapeshellarg($image->imagepath().'.raw').' > '.escapeshellarg($image->imagepath()));
            unlink($image->imagepath().'.raw');
        }
        return $image;
    }

    /** handle() as the worker runs it: an exception goes to failed() */
    protected function run(SplitImage $job)
    {
        try
        {
            $job->handle();
        }
        catch (\RuntimeException $e)
        {
            $job->failed($e);
        }
    }

    public function test_the_copy_holds_the_first_mib_and_then_each_piece_as_a_gzip_member_of_its_own()
    {
        $data = self::diskImage(4 * self::MIB + self::MIB / 2);
        $image = $this->image($data);

        $this->run(new SplitImage($image));

        $image = $image->fresh();
        $this->assertTrue($image->splitReady());
        $this->assertSame(1, $image->split_piece_mb);
        $copy = file_get_contents($image->splitPath());
        $offsets = $image->splitOffsets();
        $this->assertCount(6, $offsets, 'the first MiB, four pieces and the size');
        $this->assertSame(strlen($copy), end($offsets));
        $members = [];
        for ($i = 0; $i < count($offsets) - 1; $i++)
            $members[] = gzdecode(substr($copy, $offsets[$i], $offsets[$i + 1] - $offsets[$i]));
        $this->assertSame(substr($data, 0, self::MIB), $members[0]);
        $this->assertSame([self::MIB, self::MIB, self::MIB, self::MIB / 2], array_map('strlen', array_slice($members, 1)));
        $this->assertSame($data, implode('', $members));
        $this->assertSame($data, shell_exec('gzip -dc '.escapeshellarg($image->splitPath())), 'one stream for gzip too');
        $this->assertSame([hash('sha256', $data), strlen($data)], [$image->uncompressed_sha256, $image->uncompressed_size], 'filled in');
        $this->assertSame([null, null], [$image->split_error, $image->split_queued_at]);
        $this->assertFileDoesNotExist(SplitImage::temp($image));
        $this->assertFileExists($image->imagepath(), 'the original stays');
    }

    public function test_an_xz_image_gets_a_gzip_copy_of_the_same_image()
    {
        $data = self::diskImage(2 * self::MIB + 100);
        $image = $this->image($data, 'xz');

        $this->run(new SplitImage($image));

        $image = $image->fresh();
        $this->assertTrue($image->splitReady());
        $this->assertStringEndsWith('.gz', $image->split_filename);
        $this->assertSame($data, shell_exec('gzip -dc '.escapeshellarg($image->splitPath())));
    }

    public function test_an_image_that_changed_since_its_sha256_gets_no_copy()
    {
        $image = $this->image(self::diskImage(2 * self::MIB), 'gz', ['uncompressed_sha256' => str_repeat('f', 64)]);

        $this->run(new SplitImage($image));

        $image = $image->fresh();
        $this->assertFalse($image->splitReady());
        $this->assertSame('the image file changed since its SHA256 was computed', $image->split_error);
        $this->assertSame([], glob(public_path('uploads/.split-'.$image->id.'*')) ?: [], 'no work files left');
    }

    public function test_without_room_for_the_copy_the_job_does_not_start()
    {
        config(['cmprovision.split_space_factor' => 1e15]);
        $image = $this->image(self::diskImage(2 * self::MIB));

        $this->run(new SplitImage($image));

        $this->assertStringStartsWith('not enough free space: need about ', $image->fresh()->split_error);
    }

    public function test_a_run_cut_off_by_a_worker_restart_is_queued_again_a_few_times()
    {
        Queue::fake();
        $image = $this->image(self::diskImage(2 * self::MIB));
        file_put_contents(SplitImage::temp($image), 'half');

        (new SplitImage($image))->failed(new MaxAttemptsExceededException('cut off'));

        $this->assertFileDoesNotExist(SplitImage::temp($image));
        Queue::assertPushed(SplitImage::class, function ($job) use ($image) {
            return $job->image->id === $image->id && $job->restarts === 1;
        });
        $this->assertNull($image->fresh()->split_error);

        (new SplitImage($image, SplitImage::RESTARTS))->failed(new MaxAttemptsExceededException('cut off'));

        $this->assertSame('cut off', $image->fresh()->split_error);
    }

    public function test_the_copy_is_queued_once_on_the_images_queue()
    {
        Queue::fake();
        $image = $this->image(self::diskImage(2 * self::MIB));

        $image->queueSplit();
        $image->fresh()->queueSplit();

        Queue::assertPushedOn('images', SplitImage::class);
        Queue::assertPushed(SplitImage::class, 1);
        $this->assertNotNull($image->fresh()->split_queued_at);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `bash "$SP/wsl-bench/tests-ok.sh" 'SplitImageTest'`
Expected: FAIL — `Class "App\Jobs\SplitImage" not found`.

- [ ] **Step 3: The job** — `app/Jobs/SplitImage.php`:

```php
<?php

namespace App\Jobs;

use App\Models\Image;
use App\Support\ImagePieces;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/* The pieces copy of an image (2.6.1, App\Support\ImagePieces) for the projects with "Split the image into pieces". One pass
   decompresses the image and packs its first MiB, then every piece, as a gzip member of its own; the copy counts only once
   it decompresses to the SHA256 of that pass. The original stays as it is: a module downloading it is not disturbed. */
class SplitImage implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /* a run cut off by a restart of the queue worker (a package upgrade) is queued again this many times */
    const RESTARTS = 3;

    public $image;
    public $restarts;
    public $tries = 1;
    public $timeout = ImagePieces::SPLIT_TIMEOUT;
    public $failOnTimeout = true;
    public $deleteWhenMissingModels = true;

    public function __construct(Image $image, int $restarts = 0)
    {
        $this->image = $image;
        $this->restarts = $restarts;
        $this->onQueue('images');   // minutes of work: the worker of the image checksums (cmprovision-queue-images)
    }

    public function uniqueId()
    {
        return (string) $this->image->id;
    }

    public function handle()
    {
        $image = $this->image;
        if ($image->splitReady())
            return $this->settle($image);

        $decompress = ['gz' => 'gzip -dc', 'xz' => 'xz -dc', 'bz2' => 'bunzip2 -dc'][$image->filename_extension] ?? null;
        if (!$decompress || !is_file($image->imagepath()))
            throw new \RuntimeException('the image file is missing');
        $need = (int) ceil(filesize($image->imagepath()) * (float) config('cmprovision.split_space_factor'));
        $free = (int) disk_free_space(public_path('uploads'));
        if ($free < $need)
            throw new \RuntimeException('not enough free space: need about '.ImagePieces::gb($need).', '.ImagePieces::gb($free).' free');

        $tmp = self::temp($image);
        self::clean($image);
        $mb = ImagePieces::pieceMb();
        [$code, , $err] = self::bash('set -o pipefail; '.$decompress.' "$SRC" | tee >(sha256sum >&2)'
            .' | { dd bs=1M count=1 iflag=fullblock status=none | gzip -6n > "$OUT" && stat -c %s "$OUT" > "$IDX"'
            .' && split -b '.$mb.'M --filter=\'gzip -6n >> "$OUT" && stat -c %s "$OUT" >> "$IDX"\' -; }',
            ['SRC' => $image->imagepath(), 'OUT' => $tmp, 'IDX' => $tmp.'.index']);
        if ($code !== 0 || !preg_match('/^([0-9a-f]{64})  -$/m', $err, $m))
            throw new \RuntimeException('splitting failed: '.trim($err));
        $sha = $m[1];

        [$code, $size, $err] = self::bash('set -o pipefail; gzip -dc "$OUT" | tee >(sha256sum >&2) | wc --bytes', ['OUT' => $tmp]);
        if ($code !== 0 || !preg_match('/^([0-9a-f]{64})  -$/m', $err, $m) || $m[1] !== $sha)
            throw new \RuntimeException('the pieces do not decompress to the image');
        if ($image->uncompressed_sha256 && $image->uncompressed_sha256 !== $sha)
            throw new \RuntimeException('the image file changed since its SHA256 was computed');

        // each line is the size of the copy after one more member: with a 0 in front, where every member starts
        $offsets = array_map('intval', file($tmp.'.index', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
        array_unshift($offsets, 0);
        $size = (int) trim($size);
        if (end($offsets) !== filesize($tmp) || count($offsets) - 2 !== ImagePieces::count($size, $mb))
            throw new \RuntimeException('the pieces do not add up');

        $old = $image->splitPath();
        $name = Str::random(40).'.gz';
        rename($tmp, public_path('uploads/'.$name));
        $image->split_filename = $name;
        $image->split_offsets = json_encode($offsets);
        $image->split_piece_mb = $mb;
        $image->uncompressed_sha256 = $image->uncompressed_sha256 ?: $sha;
        $image->uncompressed_size = $image->uncompressed_size ?: $size;
        $this->settle($image);
        self::clean($image);
        if ($old && $old !== $image->splitPath() && is_file($old))
            unlink($old);
    }

    /* A run cut off by a restart of the worker goes again (the next run starts from the beginning); anything else is the
       reason the Images page shows, until Retry */
    public function failed(?\Throwable $e = null): void
    {
        $image = Image::find($this->image->id);
        if (!$image)
            return;
        self::clean($image);
        if ($e instanceof MaxAttemptsExceededException && $this->restarts < self::RESTARTS)
        {
            self::dispatch($image, $this->restarts + 1)->delay(now()->addMinute());
            return;
        }
        $image->split_error = $e ? Str::limit($e->getMessage(), 500) : 'the job failed';
        $image->split_queued_at = null;
        $image->save();
    }

    /* Where a run writes the copy before it counts */
    public static function temp(Image $image): string
    {
        return public_path('uploads/.split-'.$image->id.'.tmp');
    }

    protected function settle(Image $image)
    {
        $image->split_error = null;
        $image->split_queued_at = null;
        $image->save();
    }

    protected static function clean(Image $image)
    {
        foreach ([self::temp($image), self::temp($image).'.index'] as $file)
            if (is_file($file))
                unlink($file);
    }

    /* bash -c $script with $env: [exit code, stdout, stderr]; stderr is read to its end, which waits for the sha256sum of
       a process substitution too */
    protected static function bash(string $script, array $env): array
    {
        $proc = proc_open(['bash', '-c', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
            $env + ['PATH' => getenv('PATH') ?: '/usr/sbin:/usr/bin:/sbin:/bin', 'LC_ALL' => 'C']);
        if (!$proc)
            throw new \RuntimeException('could not start bash');
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($proc), $out, $err];
    }
}
```

- [ ] **Step 4: Queueing the copy** — in `app/Models/Image.php`, add `use App\Jobs\SplitImage;` and after `splitNeeded()`:

```php
    /* Have the pieces copy made (App\Jobs\SplitImage), unless it is there or on its way */
    function queueSplit()
    {
        if ($this->splitReady() || $this->splitPreparing())
            return;
        $this->split_error = null;
        $this->split_queued_at = now();
        $this->save();
        SplitImage::dispatch($this);
    }
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `bash "$SP/wsl-bench/tests-ok.sh" 'SplitImageTest|ImageResumeDataTest|ImageQueueTest'`
Expected: `phpunit exit 0`.

- [ ] **Step 6: Commit**

`$SP/commit-261-t4.txt`:
```
The pieces copy of an image: the first MiB and every 512 MiB as gzip members of their own

It decompresses to the image's SHA256 before it counts, fills in a missing SHA256, and the
original stays. A run cut off by a restart of the queue worker goes again.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
```
```bash
cd "$REPO" && git add app/Jobs/SplitImage.php app/Models/Image.php tests/Feature/SplitImageTest.php && git commit -q -F "$SP/commit-261-t4.txt" && git show --stat --format='%h %s' HEAD
```

---

### Task 5: The server lets a run go on

**Files:**
- Modify: `app/Models/Cm.php` (`resumableFrom()`, `stoppedInTheWrite()`, `resumeWrite()`), `app/Http/Controllers/ScriptExecuteController.php` (`startProvisoning()`, `resumeProvisioning()`, `moduleFields()`, `postinstallScripts()`, `writeData()`, `moduleScript()`, `registerProgress()`), `resources/views/scriptexecute.blade.php` (no bootloader report on a resumed run)
- Test: `tests/Feature/ImageResumeStartTest.php`

**Interfaces:**
- Consumes: Tasks 1-4 (`ImagePieces`, `Image::splitReady()`, `splitOffsets()`, `splitPath()`, `queueSplit()`, the partial's `$pieces`/`$resume`).
- Produces:
  - `Cm::resumableFrom(?Project $project = null): ?int` — the byte where the next start goes on (start of a piece), or null.
  - `Cm::stoppedInTheWrite(): bool`, `Cm::resumeWrite(string $detail, int $from): Cm`.
  - `?progress=write&durable_mb=<MiB>` raises `cms.durable_bytes` for a run with `durable_image_id`, in the write.
  - The view `scriptexecute` takes `$resumed` (bool).

- [ ] **Step 1: Write the failing test** — `tests/Feature/ImageResumeStartTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Events\CmProvisioningStarted;
use App\Jobs\SplitImage;
use App\Models\Cm;
use App\Models\Cmlog;
use App\Models\Image;
use App\Models\Project;
use App\Models\Script;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** 2.6.1: a module that stopped in the write of a project with "Split the image into pieces" goes on from its last durable
    piece; any other start writes from the beginning, as in 2.6.0 */
class ImageResumeStartTest extends TestCase
{
    use RefreshDatabase;

    const SERIAL = '10000000e611110c';
    const START = '/scriptexecute?serial='.self::SERIAL.'&model=CM4&storagesize=62500000&mac=e4:5f:01:62:9f:d3';
    const MIB = 1048576;

    protected $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file)
            if ($file && is_file($file))
                unlink($file);
        parent::tearDown();
    }

    /** An image of 4.5 MiB (the first MiB and four pieces of 1 MiB) and, unless $copy is false, its pieces copy */
    protected function imageWithCopy(bool $copy = true)
    {
        $data = str_repeat("\x55", 512).str_repeat("\0", self::MIB - 512).str_repeat('d', 3 * self::MIB + self::MIB / 2);
        $image = new Image;
        $image->filename = 'wlanpi-os.img.gz';
        $image->filename_extension = 'gz';
        $image->filename_on_server = 'resume-'.uniqid().'.gz';
        $image->sha256 = str_repeat('a', 64);
        $image->uncompressed_size = strlen($data);
        $image->uncompressed_sha256 = hash('sha256', $data);
        $image->save();
        $this->files[] = $image->imagepath();
        file_put_contents($image->imagepath(), gzencode($data));
        if ($copy)
        {
            $members = [gzencode(substr($data, 0, self::MIB))];
            for ($at = self::MIB; $at < strlen($data); $at += self::MIB)
                $members[] = gzencode(substr($data, $at, self::MIB));
            $offsets = [0];
            foreach ($members as $member)
                $offsets[] = end($offsets) + strlen($member);
            $image->split_filename = 'pieces-'.uniqid().'.gz';
            $image->split_offsets = json_encode($offsets);
            $image->split_piece_mb = 1;
            $image->save();
            $this->files[] = $image->splitPath();
            file_put_contents($image->splitPath(), implode('', $members));
        }
        return $image;
    }

    protected function project(array $attributes = [], ?Image $image = null)
    {
        $image = $image ?: $this->imageWithCopy();
        $project = Project::create(array_merge(['name' => '78', 'device' => 'cm4', 'storage' => '/dev/mmcblk0',
            'image_id' => $image->id, 'label_moment' => 'never', 'verify' => true, 'os_check' => false, 'split_image' => true], $attributes));
        Setting::updateOrCreate(['key' => 'active_project'], ['value' => $project->id]);
        return $project;
    }

    protected function cm()
    {
        return Cm::where('serial', self::SERIAL)->firstOrFail();
    }

    /** The module reports it wrote the image durably up to $mib MiB */
    protected function durable(int $mib)
    {
        $this->get('/scriptexecute?serial='.self::SERIAL.'&progress=write&detail=&sectors=&durable_mb='.$mib)->assertOk();
    }

    public function test_a_fresh_run_of_the_project_writes_its_pieces_copy()
    {
        $project = $this->project();

        $script = $this->get(self::START)->assertOk()->getContent();

        $cm = $this->cm();
        $this->assertSame($project->image_id, $cm->durable_image_id);
        $this->assertNull($cm->durable_bytes);
        $this->assertStringContainsString('/uploads/'.$project->image->split_filename."'\n", $script);
        $this->assertStringContainsString("PIECES=4\nPIECE_MB=1\n", $script);
        $this->assertStringContainsString('| { dd of="$WORK/head" bs=1M count=1 iflag=fullblock 2>>"$WRITE_LOG" && write_pieces 0; }', $script);
        $this->assertStringContainsString('vcgencmd bootloader_version', $script);
    }

    public function test_durable_marks_count_only_in_the_write_of_a_run_on_the_copy_and_only_upwards()
    {
        $this->project();
        $this->get(self::START)->assertOk();

        $this->durable(3);
        $this->assertSame(3 * self::MIB, $this->cm()->durable_bytes);
        $this->durable(2);
        $this->assertSame(3 * self::MIB, $this->cm()->durable_bytes, 'never back');
        $this->durable(4);
        $this->assertSame(4 * self::MIB, $this->cm()->durable_bytes);

        $this->cm()->update(['durable_image_id' => null, 'durable_bytes' => null]);
        $this->durable(3);
        $this->assertNull($this->cm()->durable_bytes, 'a run on the original has no marks');
    }

    public function test_a_module_that_restarted_mid_write_continues_from_its_last_durable_piece()
    {
        Event::fake([CmProvisioningStarted::class]);
        $project = $this->project();
        $pre = Script::create(['name' => 'Bench: debug shell', 'script_type' => 'preinstall', 'bg' => false, 'priority' => 1, 'script' => 'true']);
        $project->scripts()->sync([$pre->id]);
        $this->get(self::START)->assertOk();
        $this->durable(3);   // pieces 0 and 1: the image from 1 to 3 MiB
        $this->cm()->update(['eeprom_before' => 'BUILD_TIMESTAMP=1700000000', 'pre_script_output' => 'flashed']);
        $steps = count($this->cm()->timeline);

        $script = $this->get(self::START)->assertOk()->getContent();

        $cm = $this->cm();
        $this->assertSame(['write', 'Resumed after a restart at 0.0 GB of 0.0 GB'], [$cm->phase, $cm->phase_detail]);
        $this->assertCount($steps + 1, $cm->timeline);
        $last = end($cm->timeline);
        $this->assertSame(['write', 3 * self::MIB], [$last['phase'], $last['from']]);
        $this->assertSame([3 * self::MIB, 3 * self::MIB], [$cm->progress_bytes, $cm->progress_base]);
        $this->assertSame(['BUILD_TIMESTAMP=1700000000', 'flashed'], [$cm->eeprom_before, $cm->pre_script_output]);
        Event::assertDispatchedTimes(CmProvisioningStarted::class, 1);
        $this->assertTrue(Cmlog::where('cm', self::SERIAL)
            ->where('msg', 'Provisioning resumed: the module restarted during the write; continuing from 0.0 GB of 0.0 GB.')->exists());

        $copy = $project->image->fresh();
        $offsets = $copy->splitOffsets();
        $this->assertStringContainsString("\n    fetch_image ".$offsets[3].' | { gzip -dc 2>>"$WRITE_LOG"; } | write_pieces 2', $script);
        $this->assertStringContainsString("PROGRESS_DETAIL='Resumed after a restart at 0.0 GB of 0.0 GB'\nPROGRESS_BASE_SECTORS=6144\n", $script);
        $this->assertStringContainsString(chunk_split(base64_encode(substr(file_get_contents($copy->splitPath()), 0, $offsets[1])), 76, "\n"), $script);
        $this->assertStringNotContainsString('vcgencmd bootloader_version', $script, 'the bootloader "before" stays');
        $this->assertStringNotContainsString('blkdiscard -v', $script);
        $this->assertStringNotContainsString('/tmp/pre-'.$pre->id.'.sh', $script);
    }

    public function test_a_failed_write_continues_too()
    {
        $this->project();
        $this->get(self::START)->assertOk();
        $this->durable(3);
        $this->post('/scriptexecute?serial='.self::SERIAL.'&retcode=1&phase=dd', ['log' => UploadedFile::fake()
            ->createWithContent('dd.log', "no data from the provisioning server for 10 minutes (curl exit code 7)\n")])->assertOk();
        $this->assertSame(['failed', null], [$this->cm()->phase, $this->cm()->image_filename]);

        $script = $this->get(self::START)->assertOk()->getContent();

        $this->assertSame('write', $this->cm()->phase);
        $this->assertSame('wlanpi-os.img.gz', $this->cm()->image_filename, 'back after the failure cleared it');
        $this->assertStringContainsString('write_pieces 2', $script);
    }

    public function test_a_second_restart_before_a_new_piece_resumes_from_the_same_piece_again()
    {
        $this->project();
        $this->get(self::START)->assertOk();
        $this->durable(3);
        $this->get(self::START)->assertOk();
        $steps = count($this->cm()->timeline);

        $script = $this->get(self::START)->assertOk()->getContent();

        $this->assertStringContainsString('write_pieces 2', $script);
        $this->assertCount($steps + 1, $this->cm()->timeline, 'a step of its own, although it reads the same');
    }

    public function test_a_resumed_run_keeps_its_os_check()
    {
        $this->project(['os_check' => true]);
        $this->get(self::START)->assertOk();
        $this->durable(3);

        $script = $this->get(self::START)->assertOk()->getContent();

        $this->assertSame('pending', $this->cm()->os_check);
        $this->assertStringContainsString('cat >/tmp/oscheck/run << "CMPROVISIONINGEOF"', $script);
        $this->assertStringContainsString("reboot -f\n", $script);
    }

    public static function startsOver(): array
    {
        return [
            'the verification failed' => ['verify'],
            'not one piece durable' => ['short'],
            'another project is active' => ['project'],
            'the project writes another image' => ['image'],
            'the checkbox is off' => ['checkbox'],
            'the copy is gone' => ['file'],
            'the operator pressed Start over' => ['over'],
        ];
    }

    #[DataProvider('startsOver')]
    public function test_a_start_that_cannot_go_on_writes_from_the_beginning($case)
    {
        Queue::fake();
        $project = $this->project();
        $this->get(self::START)->assertOk();
        $this->durable($case === 'short' ? 1 : 3);
        switch ($case)
        {
            case 'verify':
                $cm = $this->cm();
                $cm->setPhase('verify');
                $cm->markFailed('Error during postinstall. Return code 2.')->save();
                break;
            case 'project':
                $this->project(['name' => 'other'], $project->image);
                break;
            case 'image':
                $project->update(['image_id' => $this->imageWithCopy()->id]);
                break;
            case 'checkbox':
                $project->update(['split_image' => false]);
                break;
            case 'file':
                unlink($project->image->splitPath());
                break;
            case 'over':
                $this->cm()->update(['durable_bytes' => null]);
                break;
        }

        $script = $this->get(self::START)->assertOk()->getContent();

        $this->assertStringContainsString("PROGRESS_DETAIL=''\n", $script);
        $this->assertStringContainsString('vcgencmd bootloader_version', $script);
        $this->assertNull($this->cm()->durable_bytes);
        $this->assertCount(1, $this->cm()->timeline, 'a new run');
        if ($case === 'file')
            Queue::assertPushed(SplitImage::class);
    }

    public function test_the_checkbox_without_a_copy_writes_the_original_and_has_the_copy_made()
    {
        Queue::fake();
        $project = $this->project([], $this->imageWithCopy(false));

        $script = $this->get(self::START)->assertOk()->getContent();

        $this->assertStringContainsString('/uploads/'.$project->image->filename_on_server."'\n", $script);
        $this->assertStringContainsString('| write_plain', $script);
        $this->assertNull($this->cm()->durable_image_id);
        Queue::assertPushedOn('images', SplitImage::class);
    }

    public function test_a_project_without_the_checkbox_writes_as_before()
    {
        Queue::fake();
        $project = $this->project(['split_image' => false]);

        $script = $this->get(self::START)->assertOk()->getContent();

        $this->assertStringContainsString('/uploads/'.$project->image->filename_on_server."'\n", $script);
        $this->assertStringContainsString('| write_plain', $script);
        Queue::assertNotPushed(SplitImage::class);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `bash "$SP/wsl-bench/tests-ok.sh" 'ImageResumeStartTest'`
Expected: FAIL — the script holds the original's URL and `write_plain`, `durable_image_id` stays null, every second start is a new run.

- [ ] **Step 3: The module's resume** — in `app/Models/Cm.php`, add `use App\Support\ImagePieces;` and after `failedInItsSystem()`:

```php
    /* Where the next start of this module goes on with the image write (2.6.1): the start of the first piece not durably
       written, in bytes, or null when it writes from the beginning. The run stopped in the write, with at least one piece
       durable, for the project still active with "Split the image into pieces", whose image's pieces copy is ready. */
    public function resumableFrom(?Project $project = null)
    {
        $project = $project ?: Project::getActive();
        if (!$project || !$project->split_image || !$this->durable_bytes || !$this->durable_image_id)
            return null;
        if ((int) $this->project_id !== (int) $project->id || (int) $project->image_id !== (int) $this->durable_image_id)
            return null;
        if (!$this->stoppedInTheWrite())
            return null;
        $image = $project->image;
        if (!$image || !$image->splitReady())
            return null;
        $piece = ImagePieces::resumePiece($this->durable_bytes, $image->split_piece_mb);
        return $piece >= 1 ? ImagePieces::pieceStart($piece, $image->split_piece_mb) : null;
    }

    /* The run stopped in the image write: the module restarted mid-write, or the write was its last step before it failed */
    public function stoppedInTheWrite()
    {
        if ($this->phase === 'write')
            return true;
        $entries = array_values($this->timeline ?: []);
        $count = count($entries);
        return $this->phase === 'failed' && $count >= 2 && $entries[$count - 2]['phase'] === 'write';
    }

    /* The run goes on with the write after a restart (2.6.1): always a step of its own (it notes where it starts in the
       image for RunTimeline), its clock and byte counter from there */
    public function resumeWrite(string $detail, int $from)
    {
        $this->phase = 'write';
        $this->phase_detail = $detail;
        $this->phase_started_at = now();
        $this->progress_bytes = $from;
        $this->progress_base = $from;
        $this->progress_updated_at = now();
        $timeline = $this->timeline ?: [];
        if (count($timeline) < self::MAX_TIMELINE)
            $timeline[] = ['phase' => 'write', 'detail' => $detail, 'at' => now()->getTimestamp(), 'from' => $from];
        $this->timeline = $timeline;
        return $this;
    }
```

- [ ] **Step 4: The start** — in `app/Http/Controllers/ScriptExecuteController.php`, replace the whole `startProvisoning()` method with the following, and add the five methods after it:

```php
    public function startProvisoning(Request $req)
    {
        /* The OS check (2.6.0): a module that comes back to network boot while its new system should be starting did not
           start it. That run fails and the module waits (no second write); its start after that is a new run. A USB boot
           is the operator re-flashing it, and a start long after the reboot finds a run whose timeout was lost: both close
           the waiting run and go on as a new run. */
        $previous = Cm::where('serial', $this->serial)->first();
        if ($previous && $previous->phase === 'firstboot')
        {
            $this->cm = $previous;
            $waited = now()->getTimestamp() - ($previous->phase_started_at ? $previous->phase_started_at->getTimestamp() : 0);
            if ($req->query('bootmode') == 3)
                $this->closeTheCheck('Interrupted: the module was booted over USB.');
            elseif ($waited > Cm::FIRSTBOOT_SECONDS)
                $this->closeTheCheck(Cm::OS_NO_REPORT);
            else
            {
                $this->cm->os_check = 'failed';
                return $this->refuse('The new system did not start: the module came back to network boot.');
            }
        }

        $project = Project::getActive();
        $image   = $project ? $project->image : null;

        /* The image resume (2.6.1): a module that stopped in its write goes on from its last durable piece */
        if ($previous && $project && ($from = $previous->resumableFrom($project)) !== null)
            return $this->resumeProvisioning($req, $previous, $project, $from);

        $bootmode = $req->query('bootmode');
        // with switches the board is looked up in the background (ResolveBoards): the module gets its script at once
        $lookUp = $req->query('mac') && EthernetSwitch::exists();
        // with "Split the image into pieces" the module writes the pieces copy; a missing one is made in the background
        $split = $project && $project->split_image && $image && $image->splitReady();
        if ($project && $project->split_image && $image && !$split)
            $image->queueSplit();

        $this->cm = Cm::updateOrCreate(['serial' => $this->serial], array_merge($this->moduleFields($req, $lookUp), [
            'serial' => $this->serial,
            'firmware' => $project ? $project->eeprom_firmware : null,
            'pre_script_output' => null,
            'post_script_output' => null,
            'script_return_code' => null,
            'temp2' => null,
            'project_id' => $project ? $project->id : null,
            'image_filename' => $image ? $image->filename : null,
            'image_sha256'   => $image ? $image->sha256 : null,
            'provisioning_started_at' => now(),
            'provisioning_complete_at' => null,
            'phase' => null,
            'phase_detail' => null,
            'phase_started_at' => null,
            'progress_bytes' => null,
            'progress_base' => null,
            'progress_total' => $image ? $image->uncompressed_size : null,
            'progress_updated_at' => null,
            'timeline' => [],
            'polled_at' => null,
            'identify_until' => null,
            /* set below once the project is known: a refusal before that belongs to no batch, not to the last run's */
            'notification_batch_id' => null,
            /* the module reports its bootloader once the script runs; the settings are flashed as stored in the image */
            'eeprom_before' => null,
            'eeprom_config_before' => null,
            'eeprom_config_after' => $project && $project->eeprom_firmware ? EepromImage::normalizeConfig($project->eeprom_settings) : null,
            'eeprom_result' => null,
            /* the OS check (2.6.0): pending until the image is written; a module booted over USB would only boot over
               USB again, it is not checked */
            'os_check' => $project && $project->os_check ? ($bootmode == 3 ? 'skipped' : 'pending') : null,
            'hostname' => null,
            'os_state' => null,
            'os_failed_units' => null,
            'os_checked_at' => null,
            'os_check_output' => null,
            /* the image resume (2.6.1): how far a run on the pieces copy is written for sure */
            'durable_bytes' => null,
            'durable_image_id' => $split ? $image->id : null,
        ]));

        if (!$project)
        {
            return $this->refuse("Could not provision, because there is no active project", 'No active project set in CMprovisioning');
        }

        $batch = NotificationBatch::current($project);
        $batch->touchEvent();
        $this->cm->notification_batch_id = $batch->id;
        $this->cm->save();
        if ($project->verify && $image)
        {
            if (!$image->uncompressed_sha256)
            {
                return $this->refuse("Verification enabled, but uncompressed SHA256 not computed yet, try again later...");
            }
            if ($image->uncompressed_size % 512 != 0)
            {
                return $this->refuse("Image is not a valid disk image. Uncompressed size not dividable by sector size of 512 bytes.");
            }
        }
        if ($image && $project->storage == '/dev/mmcblk0')
        {
            if (!$this->cm->storage)
            {
                return $this->refuse("Missing eMMC/SD card.");
            }
            if ($image->uncompressed_size && $this->cm->storage < $image->uncompressed_size)
            {
                return $this->refuse("Image does not fit in storage. Uncompressed image size: ".$image->uncompressed_size." bytes. Available space: ".$this->cm->storage." bytes.",
                                     'Image does not fit in storage.');
            }
        }

        $preinstall_scripts = $project->scripts()->where('script_type','preinstall')->orderBy('priority')->orderBy('id')->get();
        $server = $this->moduleServer($req);

        if ($project->eeprom_firmware)
        {
            $setting = Setting::findOrFail('active_eeprom_sha256');
            $eeprom_url = "http://$server/uploads/pieeprom.bin";
            $eeprom_sha256 = $setting->value;
            $fscript = new Script;
            $fscript->id = 0;
            $fscript->name = 'Flash EEPROM firmware ('.$project->eeprom_firmware.')';
            $fscript->bg = false;
            /* flashrom leaves the chip alone when it holds the image already; the last line says which it
               was (EEPROM_RESULT_LINE). The script runs under sh -v, which copies every script line into
               the log as well, so the result is printed from a variable and never spelled out in the script. */
            $fscript->script = "#!/bin/sh\n"
                             . "set -e\n"
                             . "curl --retry 10 --silent --show-error -g -o pieeprom.bin \"$eeprom_url\"\n"
                             . "echo \"$eeprom_sha256  pieeprom.bin\" | sha256sum -c\n"
                             . 'flashrom -p "linux_spi:dev=/dev/spidev0.0,spispeed=16000" -w "pieeprom.bin" >flashrom.log 2>&1 || { cat flashrom.log; exit 1; }'."\n"
                             . "cat flashrom.log\n"
                             . "if grep -q \"identical\" flashrom.log; then RESULT=identical; else RESULT=written; fi\n"
                             . "echo \"EEPROM_RESULT=\$RESULT\"\n";
            $preinstall_scripts->prepend($fscript);
        }

        $postinstall_scripts = $this->postinstallScripts($project, $image);

        $this->cm->setPhase(count($preinstall_scripts) ? 'preinstall' : ($image ? 'write' : 'postinstall'))->save();
        CmProvisioningStarted::dispatch($this->cm);

        $msg = "Provisioning started.";
        if ($this->cm->os_check === 'skipped')
            $msg .= " OS check skipped: the module booted over USB.";
        if ($project->label_moment == 'preinstall' && $project->label)
        {
            $msg .= $this->cm->board_pending_since ? " Printing label once the switch port is known." : " Printing label.";
        }
        if (count($preinstall_scripts))
        {
            $msg .= " Starting preinstall scripts.";
        }
        else if ($project->image)
        {
            $msg .= " Starting to write image.";
        }
        else
        {
            $msg .= " No image to write.";
        }
        $this->logInfo($msg);

        if ($project->label_moment == 'preinstall' && $project->label && !$this->cm->board_pending_since)
        {
            $this->printLabel();
        }

        $this->lookUpBoardLater();
        return $this->moduleScript($req, $project, $preinstall_scripts, $postinstall_scripts,
            $this->writeData($server, $image, $split, null), false);
    }

    /* The image resume (2.6.1): the module stopped in the write of a project that keeps its image in pieces, so the same
       run goes on from the first piece not durably written. Kept: the steps, the bootloader before and after, the
       pre-install log and the start; the batch while it is open. The module skips the bootloader report, the pre-install
       scripts and blkdiscard, and gets the first MiB of the image inside its script. */
    protected function resumeProvisioning(Request $req, Cm $previous, Project $project, int $from)
    {
        $image = $project->image;
        $total = (int) $image->uncompressed_size;
        $lookUp = $req->query('mac') && EthernetSwitch::exists();
        $this->cm = $previous;
        $this->cm->fill(array_merge($this->moduleFields($req, $lookUp), [
            'temp2' => null,
            'script_return_code' => null,
            'post_script_output' => null,
            'provisioning_complete_at' => null,
            'image_filename' => $image->filename,
            'image_sha256' => $image->sha256,
            'progress_total' => $image->uncompressed_size,
            'polled_at' => null,
            'identify_until' => null,
        ]));
        $this->cm->resumeWrite(ImagePieces::resumedDetail($from, $total), $from);
        $batch = NotificationBatch::current($project);
        $batch->touchEvent();
        $this->cm->notification_batch_id = $batch->id;
        $this->cm->save();
        $this->logInfo('Provisioning resumed: the module restarted during the write; continuing from '
            .ImagePieces::gb($from).' of '.ImagePieces::gb($total).'.');

        $this->lookUpBoardLater();
        return $this->moduleScript($req, $project, collect(), $this->postinstallScripts($project, $image),
            $this->writeData($this->moduleServer($req), $image, true, $from), true);
    }

    /* What the module tells about itself at its start (cmdline.txt of scriptexecute): the fields of its row */
    protected function moduleFields(Request $req, bool $lookUp): array
    {
        $board = null;
        $jumper = $req->query('inversejumper');
        if (!$lookUp && $jumper)
        {
            // Inverse jumper bits
            for ($i=0; $i<strlen($jumper); $i++)
                $jumper[$i] = $jumper[$i] == "0" ? "1" : "0";
            $board = $jumper.' ('.bindec($jumper).')';
        }
        return [
            'mac'    => $req->query('mac') ? $req->query('mac')
                        : "b8:27:eb:".substr($this->serial, -6, 2).":".substr($this->serial, -4, 2).":".substr($this->serial, -2, 2),
            'model'  => $req->query('model'),
            'memory_in_gb' => $req->query("memorysize") ? round( ($req->query("memorysize")+200000)/1024/1024 ) : null,
            'storage' => $req->query('storagesize') ? $req->query('storagesize')*512 : null,
            'cid' => $req->query('cid'),
            'csd' => $req->query('csd'),
            'temp1' => $req->query('temp'),
            'provisioning_board' => $board,
            'switch_id' => null,
            'switch_name' => null,
            'switch_port' => null,
            'board_pending_since' => $lookUp ? now() : null,
        ];
    }

    /* The project's post-install scripts, the generated SHA256 verification first when the project verifies */
    protected function postinstallScripts(Project $project, ?Image $image)
    {
        $postinstall_scripts = $project->scripts()->where('script_type','postinstall')->orderBy('priority')->orderBy('id')->get();
        if ($project->verify && $image)
        {
            $fscript = new Script;
            $fscript->id = 0;
            $fscript->name = 'Verifying written image';
            $fscript->bg = false;
            $fscript->progress = 'verify';   // the script counts the sectors read while it runs

            if ($image->uncompressed_size % 1048576 == 0)
                $ddline = "dd if=".$project->storage." bs=1M count=".($image->uncompressed_size / 1048576);
            else
                $ddline = "dd if=".$project->storage." count=".($image->uncompressed_size / 512);

            $fscript->script = "#!/bin/sh\n"
                             . "set -e\n"
                             . "sync; echo 3 > /proc/sys/vm/drop_caches\n"
                             . 'READ_SHA256=$('."$ddline | sha256sum | awk '{print $1}')\n"
                             . 'echo Computed SHA256: "$READ_SHA256"'."\n"
                             . 'if [ "$READ_SHA256" = "'.$image->uncompressed_sha256.'" ]; then echo Verification successful!; else echo Verification failed; exit 2; fi'."\n";
            $postinstall_scripts->prepend($fscript);
        }
        return $postinstall_scripts;
    }

    /* What the write step of the module's script needs (scriptexecute/write.blade.php): the file the module downloads (the
       pieces copy when it writes pieces), its size, the decompressor, the pieces and, for a run that goes on from $from,
       the piece, its offset in the copy and the first MiB of the image (gzip member 0) */
    protected function writeData($server, ?Image $image, bool $split, ?int $from): array
    {
        if (!$image)
            return ['image_url' => null, 'image_bytes' => null, 'decompress' => null, 'pieces' => null, 'resume' => null];
        if (!$split)
            return ['image_url' => "http://$server/uploads/".$image->filename_on_server, 'image_bytes' => (int) $image->filesize(),
                    'decompress' => self::DECOMPRESSORS[$image->filename_extension], 'pieces' => null, 'resume' => null];

        $mb = (int) $image->split_piece_mb;
        $offsets = $image->splitOffsets();
        $data = ['image_url' => "http://$server/uploads/".$image->split_filename, 'image_bytes' => (int) end($offsets),
                 'decompress' => self::DECOMPRESSORS['gz'], 'pieces' => ['count' => count($offsets) - 2, 'mb' => $mb], 'resume' => null];
        if ($from !== null)
        {
            $piece = ImagePieces::resumePiece($from, $mb);
            $head = file_get_contents($image->splitPath(), false, null, 0, $offsets[1]);
            $data['resume'] = ['piece' => $piece, 'from_byte' => $offsets[$piece + 1], 'head' => chunk_split(base64_encode($head), 76, "\n"),
                               'detail' => ImagePieces::resumedDetail($from, (int) $image->uncompressed_size),
                               'base_sectors' => intdiv($from, 512)];
        }
        return $data;
    }

    /* The module's script (resources/views/scriptexecute.blade.php), for a start or for a run that goes on */
    protected function moduleScript(Request $req, Project $project, $preinstall_scripts, $postinstall_scripts, array $write, bool $resumed)
    {
        $server = $this->moduleServer($req);
        $storage = $project->storage;
        if (is_numeric($storage[strlen($storage)-1]))
        {
            $part1 = $storage."p1";
            $part2 = $storage."p2";
        }
        else
        {
            $part1 = $storage."1";
            $part2 = $storage."2";
        }
        /* The OS check: its first-boot scripts and packages go into the written system with its service */
        $osCheck = $this->cm->os_check === 'pending';
        $firstboot_scripts = $osCheck ? $project->scripts()->where('script_type','firstboot')->orderBy('priority')->orderBy('id')->get() : collect();
        $packages = $osCheck ? $project->packages()->orderBy('packages.id')->get() : collect();

        return response()->view('scriptexecute', array_merge($write, [
            'cm' => $this->cm,
            'project' => $project,
            'storage' => $storage,
            'part1' => $part1,
            'part2' => $part2,
            'server' => $server,
            'bootmode' => $req->query('bootmode'),
            'resumed' => $resumed,
            'preinstall_scripts' => $preinstall_scripts,
            'postinstall_scripts' => $postinstall_scripts,
            'os_check' => $osCheck,
            'firstboot_scripts' => $firstboot_scripts,
            'firstboot_run' => $osCheck ? view('scriptexecute.firstboot', ['server' => $server, 'serial' => $this->cm->serial,
                'scripts' => $firstboot_scripts, 'packages' => $packages])->render() : null,
            'oscheck_install' => $osCheck ? view('scriptexecute.oscheck-install')->render() : null,
        ]))->header('Content-Type', 'text/plain');
    }
```

Add `use App\Models\Image;` to the imports if it is not there.

- [ ] **Step 5: Durable marks** — in `registerProgress()`, after `$this->cm->progress_bytes = (int) $sectors * 512;` (inside its `if`), before `$this->cm->save();`, insert:

```php
        /* the image resume (2.6.1): how far a run on the pieces copy is written for sure, in MiB (write_pieces) */
        $durable = $req->query('durable_mb');
        if (is_string($durable) && ctype_digit($durable) && $this->cm->durable_image_id && $this->cm->phase === 'write'
            && (int) $durable * 1048576 > (int) $this->cm->durable_bytes)
            $this->cm->durable_bytes = (int) $durable * 1048576;
```

- [ ] **Step 6: No bootloader report on a resumed run** — in `resources/views/scriptexecute.blade.php`, put `@if (!$resumed)` on its own line before the comment `# The bootloader as it is before provisioning: …`, and after the line `report -F 'eeprom_version=@/tmp/eeprom_version' …` add:

```blade
@else
# The run goes on after a restart (2.6.1): the run that flashed the bootloader reported it "before"
@endif
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `bash "$SP/wsl-bench/tests-ok.sh" 'ImageResume|ImageWrite|EepromTransparencyTest|OsCheck|ProvisioningProgressTest|ResolveBoardsTest|StandNetworkTest|NotificationsTest|ModuleCardTest'`
Expected: `phpunit exit 0`.

- [ ] **Step 8: Commit**

`$SP/commit-261-t5.txt`:
```
A module that stopped in its write goes on from its last durable piece

With "Split the image into pieces" a start after a restart or a failed write keeps the run:
its steps, the bootloader before and after, the batch. The module skips the bootloader
report, the pre-install scripts and blkdiscard, gets the first MiB inside its script and
downloads only from its piece. Any other start writes from the beginning, as before.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
```
```bash
cd "$REPO" && git add app/Models/Cm.php app/Http/Controllers/ScriptExecuteController.php resources/views/scriptexecute.blade.php tests/Feature/ImageResumeStartTest.php && git commit -q -F "$SP/commit-261-t5.txt" && git show --stat --format='%h %s' HEAD
```

---

### Task 6: The checkbox and the Images page

**Files:**
- Modify: `app/Http/Livewire/Projects.php`, `resources/views/livewire/editproject.blade.php`, `routes/api.php`, `app/Models/Image.php` (`splitState()`), `app/Http/Livewire/Images.php`, `resources/views/livewire/images.blade.php`
- Test: `tests/Feature/ImagePiecesPagesTest.php`

**Interfaces:**
- Consumes: `Image::queueSplit()`, `splitReady()`, `splitPreparing()`, `splitNeeded()`, `removeSplit()` (Tasks 1, 4).
- Produces: `Image::splitState(): string`; Livewire actions `Images::removeSplit($id)`, `Images::retrySplit($id)`; `PATCH /api/projects/{id}` accepts `split_image`.

- [ ] **Step 1: Write the failing test** — `tests/Feature/ImagePiecesPagesTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Http\Livewire\Images;
use App\Http\Livewire\Projects;
use App\Jobs\SplitImage;
use App\Models\Image;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/** 2.6.1: the project's checkbox "Split the image into pieces" (form and API) and the pieces copy on the Images page */
class ImagePiecesPagesTest extends TestCase
{
    use RefreshDatabase;

    protected $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file)
            if ($file && is_file($file))
                unlink($file);
        parent::tearDown();
    }

    protected function image(string $extension = 'gz')
    {
        $image = new Image;
        $image->filename = 'wlanpi-os.img.'.$extension;
        $image->filename_extension = $extension;
        $image->filename_on_server = 'pages-'.uniqid().'.'.$extension;
        $image->sha256 = str_repeat('a', 64);
        $image->save();
        return $image;
    }

    protected function copy(Image $image)
    {
        $image->split_filename = 'pieces-'.uniqid().'.gz';
        $image->split_offsets = json_encode([0, 4, 8]);
        $image->split_piece_mb = 1;
        $image->split_queued_at = null;
        $image->save();
        $this->files[] = $image->splitPath();
        file_put_contents($image->splitPath(), 'xxxxxxxx');
    }

    protected function project(Image $image, bool $split = true)
    {
        return Project::create(['name' => 'p'.uniqid(), 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'image_id' => $image->id,
            'label_moment' => 'never', 'verify' => false, 'os_check' => false, 'split_image' => $split]);
    }

    public function test_the_project_form_keeps_the_checkbox_and_has_the_copy_made()
    {
        Queue::fake();
        $image = $this->image();

        Livewire::test(Projects::class)->call('create')->set('name', '78')->set('active', false)
            ->set('image_id', $image->id)->set('split_image', true)->call('store')->assertHasNoErrors();

        $project = Project::where('name', '78')->firstOrFail();
        $this->assertTrue($project->split_image);
        Queue::assertPushedOn('images', SplitImage::class);
        Livewire::test(Projects::class)->call('edit', $project->id)->assertSet('split_image', true)
            ->assertSee('Split the image into 512 MB pieces')
            ->assertSee('A module restarted during the write continues from the last piece it wrote.')
            ->assertSee('Pieces of wlanpi-os.img.gz: preparing…');
    }

    public function test_an_xz_image_says_its_pieces_are_a_gzip_copy()
    {
        $image = $this->image('xz');

        Livewire::test(Projects::class)->call('create')->set('image_id', $image->id)->set('split_image', true)
            ->assertSee('The pieces are a gzip copy of this xz image: the download grows.');
    }

    public function test_the_api_turns_the_checkbox_on_and_has_the_copy_made()
    {
        Queue::fake();
        $project = $this->project($this->image(), false);
        Sanctum::actingAs(User::factory()->create(), ['update']);

        $this->patchJson('/api/projects/'.$project->id, ['split_image' => 'yes'])->assertStatus(422);
        $this->patchJson('/api/projects/'.$project->id, ['split_image' => true])->assertOk();

        $this->assertTrue($project->fresh()->split_image);
        Queue::assertPushed(SplitImage::class);
    }

    public function test_the_images_page_says_how_the_copy_is_and_offers_remove_and_retry()
    {
        $image = $this->image();
        Livewire::test(Images::class)->assertDontSee('Pieces:');

        $this->project($image);
        $image->split_error = 'not enough free space: need about 2.1 GB, 1.0 GB free';
        $image->save();
        Livewire::test(Images::class)->assertSee('Pieces: failed: not enough free space: need about 2.1 GB, 1.0 GB free')
            ->assertSeeHtml('wire:click="retrySplit('.$image->id.')"');

        Queue::fake();
        Livewire::test(Images::class)->call('retrySplit', $image->id)
            ->assertSee('Pieces: preparing…')->assertSeeHtml('wire:poll.5s');
        Queue::assertPushed(SplitImage::class);

        $this->copy($image->fresh());
        Livewire::test(Images::class)->assertSee('Pieces: ready, 0.0 GB')->assertDontSeeHtml('removeSplit(');

        Project::where('image_id', $image->id)->update(['split_image' => false]);
        Livewire::test(Images::class)->assertSeeHtml('wire:click="removeSplit('.$image->id.')"')
            ->call('removeSplit', $image->id)->assertSee('Pieces removed.');
        $this->assertFalse($image->fresh()->splitReady());
    }

    public function test_a_copy_a_project_needs_is_not_removed()
    {
        $image = $this->image();
        $this->copy($image);
        $this->project($image);

        Livewire::test(Images::class)->call('removeSplit', $image->id);

        $this->assertTrue($image->fresh()->splitReady());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `bash "$SP/wsl-bench/tests-ok.sh" 'ImagePiecesPagesTest'`
Expected: FAIL — `Property [$split_image] not found`, no «Pieces:» on the Images page.

- [ ] **Step 3: The copy's state** — in `app/Models/Image.php`, after `queueSplit()`:

```php
    /* What the pages say about the pieces copy */
    function splitState(): string
    {
        if ($this->splitReady())
            return 'ready, '.ImagePieces::gb((int) filesize($this->splitPath()));
        if ($this->splitPreparing())
            return 'preparing…';
        if ($this->split_error)
            return 'failed: '.$this->split_error;
        return 'not made yet';
    }
```

- [ ] **Step 4: The form** — in `app/Http/Livewire/Projects.php`:
  - `public $firmware, $eeprom_settings, $verify, $os_check, $selectedPackages, $split_image;`
  - in `rules()`, after `'os_check' => 'required|boolean',`: `'split_image' => 'required|boolean',`
  - in `create()`, after `$this->os_check = false;`: `$this->split_image = false;`
  - in `edit()`, after `$this->os_check = $p->os_check;`: `$this->split_image = $p->split_image;`
  - in `store()`, in the `updateOrCreate` array after `'os_check' => (bool) $this->os_check,`: `'split_image' => (bool) $this->split_image,`
  - in `store()`, after the `ComputeSHA256` block:

```php
        if ($this->split_image && $project->image)
        {
            /* the pieces copy (2.6.1): made in the background, until then the project writes the original */
            $project->image->queueSplit();
        }
```

In `resources/views/livewire/editproject.blade.php`, right after the `os_check` block (the `<div class="mb-4">` that ends with `@error('os_check')…</div>`), insert:

```blade
              <div class="mb-4">
                  <label class="inline-flex items-center">
                    <input type="checkbox" id="split_image" name="split_image" wire:model.live="split_image" class="form-checkbox h-6 w-6 text-accent">
                    <span class="ml-3 text-sm">Split the image into {{ \App\Support\ImagePieces::pieceMb() }} MB pieces</span>
                  </label>
                  <p class="text-xs text-fg-muted mt-1">A module restarted during the write continues from the last piece it wrote. Turn on the verification too: it checks the whole written image, including the part written before the restart.</p>
                  @if ($split_image && ($chosen = $images->firstWhere('id', $image_id)))
                  <p class="text-xs text-fg-muted mt-1">Pieces of {{ $chosen->filename }}: {{ $chosen->splitState() }}</p>
                  @if (in_array($chosen->filename_extension, ['xz', 'bz2'], true))
                  <p class="text-xs text-fg-muted mt-1">The pieces are a gzip copy of this {{ $chosen->filename_extension }} image: the download grows.</p>
                  @endif
                  @endif
                  @error('split_image') <span class="text-neg">{{ $message }}</span>@enderror
              </div>
```

- [ ] **Step 5: The API** — in `routes/api.php`, in the `PATCH /projects/{projectId}` validation, after `'os_check' => 'sometimes|boolean',`: `'split_image' => 'sometimes|boolean',`. After the `scripts()->sync(...)` block, before the `try` with `refreshIfActive`:

```php
    if ($project->split_image && $project->image)
        $project->image->queueSplit();   // the pieces copy (2.6.1)
```

- [ ] **Step 6: The Images page** — in `app/Http/Livewire/Images.php`:
  - `public $images, $maxfilesize, $freediskspace, $hashPending = false, $splitPending = false;`
  - in `render()`, after `$this->hashPending = …;`: `$this->splitPending = $this->images->contains(function ($image) { return $image->splitPreparing(); });`
  - after `delete()`:

```php
    /* The pieces copy (2.6.1) that no project with "Split the image into pieces" needs */
    public function removeSplit($id)
    {
        $image = Image::findOrFail($id);
        if ($image->splitNeeded())
        {
            session()->flash('message', 'A project with "Split the image into pieces" writes this image: its pieces stay.');
            return;
        }
        $image->removeSplit();
        session()->flash('message', 'Pieces removed.');
    }

    public function retrySplit($id)
    {
        $image = Image::findOrFail($id);
        if ($image->splitNeeded())
            $image->queueSplit();
    }
```

In `resources/views/livewire/images.blade.php`:
  - first line: `<div class="cmp-page" @if (($hashPending || $splitPending) && !$isOpen) wire:poll.5s @endif>`
  - in the Size cell, after the `Uncomp.:` line:

```blade
                            @if ($i->split_filename || $i->split_queued_at || $i->split_error || $i->splitNeeded())
                            <br><nobr>Pieces: {{ $i->splitState() }}</nobr>
                            @endif
```
  - in the Action cell, after the Delete button:

```blade
                            @if ($i->splitReady() && !$i->splitNeeded())
                            <button wire:click="removeSplit({{ $i->id }})" class="cmp-btn cmp-btn-secondary cmp-btn-small">Remove pieces</button>
                            @elseif (!$i->splitReady() && !$i->splitPreparing() && $i->splitNeeded())
                            <button wire:click="retrySplit({{ $i->id }})" class="cmp-btn cmp-btn-secondary cmp-btn-small">Retry pieces</button>
                            @endif
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `bash "$SP/wsl-bench/tests-ok.sh" 'ImagePiecesPagesTest|OsCheckSettingsTest|ProjectsFirmwareTest|FirmwarePagesTest|TokenGuardTest|FormFieldsTest|PageLayoutTest'`
Expected: `phpunit exit 0`.

- [ ] **Step 8: Commit**

`$SP/commit-261-t6.txt`:
```
The project checkbox "Split the image into 512 MB pieces", and the copy on the Images page

Saving the project (form or API) has the copy made; the Images page shows whether it is
being made, ready or failed, removes one no project needs and retries a failed one.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
```
```bash
cd "$REPO" && git add app/Http/Livewire/Projects.php resources/views/livewire/editproject.blade.php routes/api.php app/Models/Image.php app/Http/Livewire/Images.php resources/views/livewire/images.blade.php tests/Feature/ImagePiecesPagesTest.php && git commit -q -F "$SP/commit-261-t6.txt" && git show --stat --format='%h %s' HEAD
```

---

### Task 7: What the operator reads — card, steps, advice, restart dialogs

**Files:**
- Modify: `app/Http/Livewire/CmCard.php`, `resources/views/livewire/cm-card.blade.php`, `app/Support/RunTimeline.php`, `app/Support/FailureAdvice.php`, `app/Services/Switch/PoeRestart.php`, `app/Http/Livewire/ProvisioningStatus.php`, `resources/views/livewire/provisioning-status.blade.php`
- Test: `tests/Feature/ImageResumeTextsTest.php`

**Interfaces:**
- Consumes: `Cm::resumableFrom()` (Task 5), `ImagePieces::gb()` (Task 1), the timeline key `from` (Task 5).
- Produces: `CmCard::startOver()`; `PoeRestart::confirmFailed(int $count, int $resuming = 0)`; `ProvisioningStatus::resuming($cms, ?Project $active): int`.

- [ ] **Step 1: Write the failing test** — `tests/Feature/ImageResumeTextsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Http\Livewire\CmCard;
use App\Http\Livewire\ProvisioningStatus;
use App\Models\Cm;
use App\Models\Cmlog;
use App\Models\Image;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Services\Switch\PoeRestart;
use App\Support\FailureAdvice;
use App\Support\RunTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** 2.6.1: what the card, the steps, the advice and the restart dialogs say about a write that would go on */
class ImageResumeTextsTest extends TestCase
{
    use RefreshDatabase;

    protected $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-16 10:00:00');
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file)
            if ($file && is_file($file))
                unlink($file);
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A module whose image write failed at 3.5 GB of 6.2 GB, in an active project with the checkbox and a ready copy:
        the next start goes on from piece 6 (3.2 GB) */
    protected function resumable(array $attributes = [])
    {
        $image = new Image;
        $image->filename = 'wlanpi-os.img.gz';
        $image->filename_extension = 'gz';
        $image->filename_on_server = 'texts-'.uniqid().'.gz';
        $image->sha256 = str_repeat('a', 64);
        $image->uncompressed_size = 6200000000;
        $image->split_filename = 'pieces-'.uniqid().'.gz';
        $image->split_offsets = json_encode([0, 10, 20, 30]);
        $image->split_piece_mb = 512;
        $image->save();
        $this->files[] = $image->splitPath();
        file_put_contents($image->splitPath(), str_repeat('x', 30));
        $project = Project::create(['name' => '78', 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'image_id' => $image->id,
            'label_moment' => 'never', 'verify' => true, 'os_check' => false, 'split_image' => true]);
        Setting::updateOrCreate(['key' => 'active_project'], ['value' => $project->id]);
        return Cm::create(array_merge(['serial' => '10000000e611110c', 'mac' => 'e4:5f:01:62:9f:d3', 'project_id' => $project->id,
            'phase' => 'failed', 'phase_detail' => 'Error during dd. Return code 1.', 'provisioning_started_at' => now()->subMinutes(30),
            'progress_total' => 6200000000, 'progress_bytes' => 3500000000, 'durable_bytes' => 3500000000, 'durable_image_id' => $image->id,
            'timeline' => [['phase' => 'write', 'detail' => null, 'at' => now()->getTimestamp() - 600],
                           ['phase' => 'failed', 'detail' => 'Error during dd. Return code 1.', 'at' => now()->getTimestamp()]]], $attributes));
    }

    public function test_the_card_offers_to_start_over_a_module_that_would_go_on()
    {
        $cm = $this->resumable();
        $this->actingAs(User::factory()->create(['name' => 'igor']));

        Livewire::test(CmCard::class, ['serial' => $cm->serial])
            ->assertSee('the next start continues from 3.2 GB of 6.2 GB')
            ->call('startOver')
            ->assertDontSee('the next start continues from');

        $this->assertNull($cm->fresh()->durable_bytes);
        $this->assertTrue(Cmlog::where('cm', $cm->serial)
            ->where('msg', 'Start over: the next start writes the image from the beginning (by igor).')->exists());
    }

    public function test_a_module_that_writes_normally_shows_no_resume_line()
    {
        $cm = $this->resumable(['phase' => 'write', 'phase_detail' => null, 'progress_updated_at' => now(),
            'timeline' => [['phase' => 'write', 'detail' => null, 'at' => now()->getTimestamp() - 60]]]);

        Livewire::test(CmCard::class, ['serial' => $cm->serial])->assertDontSee('the next start continues from');
    }

    public function test_the_advice_for_a_write_that_would_go_on()
    {
        $cm = $this->resumable();
        $this->assertStringEndsWith('Restart it (power cycle): it continues the write from 3.2 GB of 6.2 GB.', FailureAdvice::for($cm)['advice']);

        unlink(Image::first()->splitPath());
        $this->assertStringEndsWith(FailureAdvice::RESTART, FailureAdvice::for($cm->fresh())['advice'], 'without the copy it starts over');
    }

    public function test_the_advice_for_a_silent_module_that_would_go_on()
    {
        $cm = $this->resumable(['phase' => 'write', 'phase_detail' => null, 'progress_updated_at' => now()->subMinutes(5),
            'timeline' => [['phase' => 'write', 'detail' => null, 'at' => now()->getTimestamp() - 600]]]);

        $this->assertStringEndsWith('then restart it; it continues the write from 3.2 GB of 6.2 GB.', FailureAdvice::for($cm)['advice']);
    }

    public function test_the_restart_dialogs_say_the_write_goes_on()
    {
        $cm = $this->resumable();

        $this->assertStringEndsWith('the module boots from the network and continues the write from 3.2 GB of 6.2 GB.',
            PoeRestart::confirm($cm, 'sw01 Gi0/1'));
        $this->assertSame('Restart 3 modules (failed or without a report)? PoE goes off for 5 s on each port. '
            .'For 2 of them the write goes on where it stopped.', PoeRestart::confirmFailed(3, 2));
        $this->assertSame('Restart 3 modules (failed or without a report)? PoE goes off for 5 s on each port.', PoeRestart::confirmFailed(3));
        $this->assertSame(1, ProvisioningStatus::resuming(collect([$cm, new Cm(['phase' => 'failed'])]), Project::first()));
    }

    public function test_the_steps_of_a_run_that_went_on()
    {
        $cm = new Cm(['serial' => '10000000e611110c', 'progress_total' => 6000000000, 'progress_bytes' => 6000000000, 'timeline' => [
            ['phase' => 'write', 'detail' => null, 'at' => 1000],
            ['phase' => 'write', 'detail' => 'Resumed after a restart at 3.0 GB of 6.0 GB', 'at' => 1500, 'from' => 3000000000],
            ['phase' => 'verify', 'detail' => null, 'at' => 1800],
            ['phase' => 'done', 'detail' => null, 'at' => 1900]]]);

        $steps = RunTimeline::steps($cm);

        $this->assertNull($steps[0]['speed'], 'the cut-off write: how far it got is not known');
        $this->assertEqualsWithDelta(10000000, $steps[1]['speed'], 1, '3 GB in 300 s');
        $this->assertEqualsWithDelta(60000000, $steps[2]['speed'], 1, 'the verification reads the whole image');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `bash "$SP/wsl-bench/tests-ok.sh" 'ImageResumeTextsTest'`
Expected: FAIL — no Resume line, `Method startOver does not exist`, the old advice and dialog texts.

- [ ] **Step 3: The card** — in `app/Http/Livewire/CmCard.php`, after `stopIdentify()`:

```php
    /* The next start writes the image from the beginning instead of going on from the last durable piece (2.6.1) */
    public function startOver()
    {
        $cm = Cm::where('serial', $this->serial)->firstOrFail();
        if ($cm->durable_bytes === null)
            return;
        $cm->durable_bytes = null;
        $cm->save();
        Cmlog::create(['cm' => $cm->serial, 'board' => $cm->provisioning_board, 'loglevel' => 'info',
            'msg' => 'Start over: the next start writes the image from the beginning (by '.(auth()->user()->name ?? 'unknown').').']);
    }
```

and in `render()`, add to the view data: `'resumeFrom' => $cm->resumableFrom(),`.

In `resources/views/livewire/cm-card.blade.php`, after the `@endif` that closes the «On the bench» row (`@if (in_array($cm->phase, ['done', 'failed'], true))`), insert:

```blade
                @if ($resumeFrom !== null && ($cm->phase === 'failed' || $cm->silentFor() !== null))
                <tr>
                    <td class="text-fg-muted">Resume</td>
                    <td>
                        the next start continues from {{ \App\Support\ImagePieces::gb($resumeFrom) }} of {{ \App\Support\ImagePieces::gb((int) $cm->progress_total) }}
                        <button wire:click="startOver" wire:confirm="Write the image from the beginning at the next start?" class="ml-2 cmp-btn cmp-btn-secondary cmp-btn-small">Start over</button>
                    </td>
                </tr>
                @endif
```

- [ ] **Step 4: The steps** — in `app/Support/RunTimeline.php`, replace the speed block of `steps()` (from `$speed = null;` to its closing `}`) with:

```php
            $speed = null;
            $resumed = isset($entry['from']);   // the write went on after a restart from this byte (2.6.1)
            if ($seconds && ($entry['detail'] === null || $resumed) && in_array($entry['phase'], ['write', 'verify'], true)
                && !self::goesOnLater($entries, $i))
            {
                /* a step cut short by a failure got as far as the byte counter; otherwise the whole image */
                $bytes = ($next['phase'] === 'failed' ? (int) $cm->progress_bytes : (int) $cm->progress_total) - ($resumed ? $entry['from'] : 0);
                $speed = $bytes > 0 ? $bytes / $seconds : null;
            }
```

and add the method:

```php
    /* A write that went on after a restart follows entry $i: how far the run of entry $i got is not known */
    protected static function goesOnLater(array $entries, int $i): bool
    {
        foreach (array_slice($entries, $i + 1) as $later)
            if (isset($later['from']))
                return true;
        return false;
    }
```

- [ ] **Step 5: The advice** — in `app/Support/FailureAdvice.php` (`ImagePieces` is in the same namespace, `App\Support`, so it needs no import):
  - in the silent branch, replace `.'and the switch port, then restart it; provisioning starts over.',` with:

```php
                           .'and the switch port, then restart it; '.(($from = $cm->resumableFrom()) !== null ? self::goesOn($cm, $from) : 'provisioning starts over.'),
```
  - in the `Error during dd` branch, replace the `return` with:

```php
            $from = $cm->resumableFrom();
            return ['step' => $step, 'advice' => 'The image write stopped'.$what.'. The partition table was cleared, so the module '
                                                 .'starts from the network again. '.($from !== null ? 'Restart it (power cycle): '.self::goesOn($cm, $from) : self::RESTART)];
```
  - add the method:

```php
    /* "it continues the write from 3.2 GB of 6.2 GB." (the image resume, 2.6.1) */
    protected static function goesOn(Cm $cm, int $from)
    {
        return 'it continues the write from '.ImagePieces::gb($from).' of '.ImagePieces::gb((int) $cm->progress_total).'.';
    }
```

- [ ] **Step 6: The restart dialogs** — in `app/Services/Switch/PoeRestart.php`, add `use App\Support\ImagePieces;`. In `confirm()`, after the `firstboot` branch and before `elseif ($cm->isRunning() && $cm->silentFor() === null)`, insert:

```php
        elseif (($from = $cm->resumableFrom()) !== null)
            $then = ($cm->isRunning() && $cm->silentFor() === null ? 'its write stops and continues' : 'the module boots from the network and continues the write')
                .' from '.ImagePieces::gb($from).' of '.ImagePieces::gb((int) $cm->progress_total).'.';
```

Replace `confirmFailed()` with:

```php
    public static function confirmFailed(int $count, int $resuming = 0): string
    {
        return 'Restart '.$count.' '.($count === 1 ? 'module' : 'modules').' (failed or without a report)? PoE goes off for '
            .self::OFF_SECONDS.' s on each port.'.($resuming ? ' For '.$resuming.' of them the write goes on where it stopped.' : '');
    }
```

In `app/Http/Livewire/ProvisioningStatus.php`, add `use App\Models\Project;`, the method:

```php
    /* How many of $cms go on with their write after a restart (2.6.1) */
    public static function resuming($cms, ?Project $active): int
    {
        return collect($cms)->filter(function (Cm $cm) use ($active) { return $cm->resumableFrom($active) !== null; })->count();
    }
```

and in `render()` replace the `'failedToRestart' => …` entry with:

```php
            'failedToRestart' => count($restartable),
            'resumingToRestart' => self::resuming($restartable, Project::getActive()),
```

with, before `return view(…)`:

```php
        $restartable = $summary && $summary->open ? $summary->modules->filter(function ($cm) use ($canRestart) {
            return ($cm->phase === 'failed' || $cm->silentFor() !== null) && $canRestart($cm);
        }) : collect();
```

In `resources/views/livewire/provisioning-status.blade.php`, line 28: `wire:confirm="{{ \App\Services\Switch\PoeRestart::confirmFailed($failedToRestart, $resumingToRestart) }}"`.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `bash "$SP/wsl-bench/tests-ok.sh" 'ImageResumeTextsTest|ModuleCardTest|FailureAdvice|PoeRestart|ProvisioningStatus|DashboardTest|TokenGuardTest'`
Expected: `phpunit exit 0`.

- [ ] **Step 8: Commit**

`$SP/commit-261-t7.txt`:
```
The pages say when a restart continues the write, and the card can start over instead

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
```
```bash
cd "$REPO" && git add app/Http/Livewire/CmCard.php resources/views/livewire/cm-card.blade.php app/Support/RunTimeline.php app/Support/FailureAdvice.php app/Services/Switch/PoeRestart.php app/Http/Livewire/ProvisioningStatus.php resources/views/livewire/provisioning-status.blade.php tests/Feature/ImageResumeTextsTest.php && git commit -q -F "$SP/commit-261-t7.txt" && git show --stat --format='%h %s' HEAD
```

---

### Task 8: Docs, CSS, the whole suite, the package on the bench

**Files:**
- Modify: `CLAUDE.md`, `README.md`

- [ ] **Step 1: CLAUDE.md.** In «Провижининг: сквозной поток», step 2, replace the sentence that starts «затем `curl` образа | распаковка | `dd`» up to «…поэтому для `/uploads/` в nginx стоит `send_timeout 6h` (`debian/cmprovision`, для старых установок дописывает `postinst`).» with:

```markdown
затем запись образа (`resources/views/scriptexecute/write.blade.php`, 2.6.1, спека `docs/superpowers/specs/2026-10-16-image-resume-design.md`): `fetch_image | распаковка | запись`. Оборванную загрузку `fetch_image` продолжает с принятого байта (`curl -C`, байты считает сам curl через `-w '%{stderr}FETCHED %{size_download}'`: BusyBox `dd` числа байт не печатает), а распаковщик и `dd` работают всё время и обрыва не видят; 5xx и сбои связи — повтор раз в 5 с, 10 минут без байта — провал, 4xx и код 33 (сервер отдал файл целиком) — провал сразу, упавший распаковщик (код 23) — тоже сразу. `--speed-limit` нет: пока `dd` пишет длинные нули, curl законно стоит минутами (для `/uploads/` в nginx поэтому `send_timeout 6h`), мёртвую связь ловит `--keepalive-time 15`. Первый мебибайт (таблица разделов) явно зануляется после `blkdiscard` и пишется последним: модуль, обесточенный посреди записи, с eMMC не грузится и уходит в сеть. Суммы байт на модуле — через awk (`bytes_add`, `bytes_at_least`: оболочка может быть 32-битной). Потом пост-скрипты (первым — сгенерированная сверка SHA256, если включён `verify`).
```

and after the paragraph «3. `?alldone=1` — …» add:

```markdown
Докачка после перезагрузки (2.6.1) — только в проектах с галкой `split_image` («Split the image into 512 MB pieces»). Задача `App\Jobs\SplitImage` (очередь `images`) делает кусковую копию образа: один `.gz`, член 0 — первый мебибайт, каждый следующий — кусок по `cmprovision.split_piece_mb` (512) МиБ; смещения членов — `images.split_offsets`, состояние — `split_error`, `split_queued_at`; копия засчитывается, только распаковавшись в тот же SHA256, оригинал не трогается. Модуль пишет копию `write_pieces`: кусок за куском с `fsync`, кусок k−1 объявляется надёжным, когда кусок k получил данные (CRC члена проверен), и отметка в МиБ уходит отметкой прогресса `durable_mb` в `cms.durable_bytes` (только для прогона с `cms.durable_image_id`). Старт решает `Cm::resumableFrom()`: прошлый прогон остановился на записи (фаза `write` или провал сразу после неё), надёжен хотя бы кусок, тот же проект с галкой и тот же образ, копия готова. Тогда `resumeProvisioning()` продолжает тот же прогон (шаг «Resumed after a restart at …» с ключом `from` в `timeline`, `progress_base` для скорости), без доклада загрузчика, пре-скриптов и `blkdiscard`, с первым мебибайтом внутри скрипта (base64 члена 0). Кнопка Start over в карточке очищает `durable_bytes`.
```

In «Тесты», after the sentence about `EepromTransparencyTest`, add: «`ImageWriteScriptTest` гоняет запись под настоящим `busybox sh` с апплетами утилитарной ОС, подставным curl (обрывы, 4xx/5xx, «весь файл вместо хвоста»), «eMMC»-файлом и своими часами; без busybox тест пропускается. Копию делает `SplitImageTest` (нужны bash, split, gzip, xz), решение при старте — `ImageResumeStartTest`, страницы — `ImagePiecesPagesTest` и `ImageResumeTextsTest`.»

- [ ] **Step 2: README.md.** Insert this section between «## The OS check after the first boot ##» and «## The built-in Wi-Fi as a cold spare ##» (one blank line before and after):

```markdown
## The image download resumes ##

A broken connection no longer fails the write. The module downloads, decompresses and writes the image in one stream; when the connection breaks (a switch hiccup, an nginx restart, a re-plugged cable), curl starts again from the byte it got to while the decompressor and `dd` keep running. Server errors (5xx) and lost connections are retried every 5 seconds; after 10 minutes without a byte the write fails, and an answer like 404 (the image was deleted) fails it at once.

The partition table (the first MiB of the image) is cleared before the write and written last. A module that loses power mid-write, a PoE restart included, finds no partition table on its eMMC and boots from the network again instead of a half-written image.

A project with **Split the image into 512 MB pieces** goes further: a module restarted during the write, or after a failed write, continues from its last verified piece and downloads only the rest.

- The server keeps a gzip copy of the image in pieces next to the original, made in the background after the project is saved (about 5 minutes for a 6 GB image on a CM4); the Images page shows its state. Until it is ready the project writes the original, and a restart starts over.
- A piece counts once the module has written it with `fsync` and the decompressor has checked its checksum; the run goes on from the first piece that does not count. Turn on **Verify** too: it checks the whole image, including the part written before the restart.
- The module card shows where the next start continues; **Start over** makes it write from the beginning instead.
- For an `.xz` or `.bz2` image the copy is gzip, so the download grows.
```

- [ ] **Step 3: CSS.** The new markup uses classes the templates already had. Check it on the bench:

Run: `( . "$SP/wsl-bench/dev.sh"; sync_dev >/dev/null; w sh /root/bench/25-css.sh; from_dev public/css/app.css ) && cd "$REPO" && git status --short public/css/app.css`
Expected: no output from `git status` (the rebuilt CSS is the committed one). If it shows the file, add it to this task's commit.

- [ ] **Step 4: The whole suite**

Run: `bash "$SP/wsl-bench/suite-ok.sh"`
Expected: `php rc=0 js rc=0`; about 1030 PHP tests, the same 13 skipped as before (none of the new ones skipped on the bench).

- [ ] **Step 5: The package on the bench.** Build and install the working tree's `.deb` on the bench (it carries 2.6.1~dev1 and migrates):

Run: `( . "$SP/wsl-bench/dev.sh"; (cd "$REPO" && git archive HEAD) | w_raw sh -c "rm -rf /root/src && mkdir -p /root/src && tar -x -C /root/src"; w sh /root/bench/03-deb.sh | tail -5 )`
Expected: the last lines show `cmprovision4 2.6.1~dev1` installed and `migrate` without errors.

Then check the columns, as www-data (root does not open the database), with a script on stdin (wsl.exe expands `$` in its arguments too early):

```bash
cat <<'EOF' | MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sh -s 2>&1 | grep -v "systemd user session"
runuser -u www-data -- sqlite3 -readonly /var/lib/cmprovision/database/database.sqlite "select count(*) from pragma_table_info('cms') where name in ('durable_bytes','durable_image_id','progress_base')"
EOF
```
Expected: `3`.

- [ ] **Step 6: Commit**

`$SP/commit-261-t8.txt`:
```
Docs for the image resume

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
```
```bash
cd "$REPO" && git add CLAUDE.md README.md && git commit -q -F "$SP/commit-261-t8.txt" && git show --stat --format='%h %s' HEAD
```

---

### Task 9: Release 2.6.1 — only on the user's word

Nothing here runs without the user saying so: the release commit, the merge, the tag, the build and install on wlanpi-da2, the live checks on sensor0 and the push each wait for their word.

- [ ] **Step 1: Ask the user** for the release and, separately, for the live checks on sensor0.

- [ ] **Step 2: The release commit.** Replace the `2.6.1~dev1` entry of `debian/changelog` with:

```
cmprovision4 (2.6.1) unstable; urgency=medium

  * The image download resumes where a broken connection left it, and
    the partition table is written last: a module that loses power
    mid-write boots from the network again, not a half-written image.
  * Split the image into 512 MB pieces (project checkbox): a module
    restarted during the write, or after a failed write, continues
    from its last verified piece and downloads only the rest.

 -- nimbo78 <igor.sin.78@gmail.com>  <date of the day in the same format>

```

In `ROADMAP.md`, move «Докачка образа» from section 3 to the released list: «**2.6.1** вышла <дата>, тег `v2.6.1`. Докачка образа: обрыв связи и перезапуск посреди записи (с галкой проекта) не начинают запись заново.» Commit (`2.6.1: the image download resumes`).

- [ ] **Step 3: Merge and tag locally** (on the user's word):

```bash
cd "$REPO" && git checkout main && git merge --no-ff stage2 -m "Merge stage2: release 2.6.1" && git tag v2.6.1 && git checkout stage2
```

- [ ] **Step 4: Build and install on wlanpi-da2 from the tag** — the flow of `docs/deploy-to-provisioner.md`, from Git Bash:

```bash
cd "$REPO" && git checkout v2.6.1
ssh da2 'rm -rf ~/cmprovision/build/cmprovision && mkdir -p ~/cmprovision/build/cmprovision'
git archive v2.6.1 | ssh da2 'tar -x -C ~/cmprovision/build/cmprovision'
ssh da2 'cd ~/cmprovision/build/cmprovision && dpkg-buildpackage -b -us -uc -d'
ssh da2 'sudo dpkg -i ~/cmprovision/build/cmprovision4_2.6.1_all.deb'
git checkout stage2
```
Expected: `dpkg-query -W cmprovision4` on da2 says `2.6.1`; Settings → Service status green.

- [ ] **Step 5: Live checks on sensor0 — only on the user's word:**
  1. BusyBox gunzip of the utility OS unpacks the concatenated members (a project with the checkbox writes and verifies);
  2. `dd iflag=fullblock` with `seek` and `conv=fsync,notrunc` writes `mmcblk0`;
  3. a broken download (restart nginx on da2 mid-write) is resumed: a «Download resumed at …» line in the History, the write completes, verify passes;
  4. a PoE restart mid-write, in a project with the checkbox and `verify`: the module goes on from its piece, verify passes;
  5. a module powered off mid-write does not boot from eMMC and comes back to network boot.

- [ ] **Step 6: Push** — only after the install and the checks are fine:

```bash
cd "$REPO" && git push origin main stage2 v2.6.1
```
