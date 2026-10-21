<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Events\CmProvisioningComplete;
use App\Events\CmProvisioningFailed;
use App\Events\CmProvisioningStarted;
use App\Jobs\FirstBootTimeout;
use App\Jobs\PrintLabel;
use App\Jobs\ResolveBoards;
use App\Models\Cm;
use App\Models\Cmlog;
use App\Models\EthernetSwitch;
use App\Models\Image;
use App\Models\NotificationBatch;
use App\Models\Project;
use App\Models\Script;
use App\Models\Setting;
use App\Services\EepromImage;
use App\Services\LabelPrinter;
use App\Support\ImagePieces;
use App\Support\ModuleDiagnostics;
use App\Services\ModuleOperationLock;
use App\Services\Sensor\ReprovisionAgent;
use App\Services\Sensor\ProvisionerKey;
use App\Services\Sensor\ReprovisionIntent;
use App\Models\ReprovisionOperation;

class ScriptExecuteController extends Controller
{
    public $serial, $cm;
    const MAX_LOG_SIZE = 1*1024*1024;
    const DECOMPRESSORS = ['gz' => 'gzip -dc', 'xz' => 'xz -dc', 'bz2' => 'bunzip2 -dc'];
    /* Phases a module may report with ?progress= (see progress_mark in scriptexecute.blade.php) */
    const PROGRESS_PHASES = ['preinstall', 'write', 'verify', 'postinstall'];
    /* The line the generated flash script prints after flashrom: the chip was written, or it held the image already */
    const EEPROM_RESULT_LINE = '/^EEPROM_RESULT=(written|identical)\s*$/m';

    /* What a run that goes on finds on a storage written since its write stopped (storage_unchanged in
       scriptexecute/write.blade.php): its marks no longer hold */
    const STORAGE_WRITTEN = 'the storage was written after the write stopped';

    /* What the output of a failed image write usually means. Pattern => explanation. */
    const WRITE_FAILURE_HINTS = [
        '/'.self::STORAGE_WRITTEN.'/' => self::STORAGE_WRITTEN.' (flashed elsewhere, or another card), so the next start writes the image from the beginning',
        '/the image on the server ends at \d+ of \d+ bytes/' => 'the image file on the server is shorter than expected (damaged or replaced)',
        '/the first MiB in the script is broken/' => 'the first MiB of the pieces copy is damaged',
        '/no data from the provisioning server for \d+ minutes/' => 'the provisioning server sent nothing for 10 minutes (network or server down)',
        '/curl exit code 22\b/' => 'the server answered with an error: the image or its pieces were removed',
        '/curl exit code 33\b/' => 'the server would not send the rest of the file',
        '/curl exit code (18|56|55|52)\b/' => 'the connection to the provisioning server was closed before the download finished (server timeout, network problem or server restart)',
        '/curl exit code (7|28)\b/'        => 'the module could not reach the provisioning server (connection refused or timed out)',
        '/curl exit code 23\b/'            => 'the decompressor stopped accepting data; see its message below',
        '/curl exit code 6\b/'             => 'the provisioning server name could not be resolved',
        '/(invalid magic|not in gzip format|File format not recognized|unsupported compression|Not a bzip2 file)/i' => 'the downloaded data is not a valid compressed image (wrong file type, or an HTTP error page instead of the image)',
        '/(unexpected end of (file|input)|bunzip error -3\b|corrupt|crc error|data integrity)/i' => 'the compressed image is truncated or corrupted (interrupted download or a bad upload)',
        '/(No space left on device|cannot open .*mmcblk|No such file or directory)/i' => 'the storage device could not be written (missing, too small or failing eMMC/SD card)',
        '/Input\/output error/i' => 'the storage device reported an I/O error (failing eMMC/SD card or power problem)',
    ];

    /**
     * Handle the incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function __invoke(Request $req)
    {
        $this->serial = $req->query('serial');
        if (!$this->serial)
            abort(401);

        // Bad or stale diagnostic uploads must never fail or restart a provisioning run.
        if ($req->query('diagnostics') === '1')
            return $this->registerModuleDiagnostics($req);

        try
        {
            if ($req->query("identify"))
            {
                return $this->identifyPoll($req);
            }
            else if ($req->query("alldone"))
            {
                return $this->provisioningComplete($req);
            }
            else if ($req->query("firstboot"))
            {
                return $this->registerFirstBoot($req);
            }
            else if ($req->hasFile("log"))
            {
                return $this->registerLogFile($req);
            }
            else if ($req->hasFile("eeprom_version"))
            {
                return $this->registerFirmware($req);
            }
            else if ($req->query("progress"))
            {
                return $this->registerProgress($req);
            }
            else
            {
                return $this->startProvisoning($req);
            }
        }
        catch (\Throwable $e)
        {
            /* The module runs whatever we answer and retries an HTTP 500 for a quarter of an hour,
               so answer with a script that shows the problem, and keep it in the web log too */
            report($e);
            $msg = "Provisioning server error: ".$e->getMessage()." (".basename($e->getFile()).":".$e->getLine().")";
            $this->logInfo($msg, 'error');
            try
            {
                if ($this->cm)
                {
                    $this->cm->markFailed($msg)->save();
                    $this->failed();
                    $this->lookUpBoardLater();
                }
            }
            catch (\Throwable $ignored)
            {
            }

            try
            {
                return $this->failureScript($req, $msg);
            }
            catch (\Throwable $ignored)
            {
                return response("#!/bin/sh
echo ".escapeshellarg($msg)."
exit 1
", 200)
                    ->header('Content-Type', 'text/plain');
            }
        }
    }

    /* Address of this server as the module reached it, for the links in the module's script */
    protected function moduleServer(Request $req)
    {
        return (string) $req->server('HTTP_HOST');
    }

    /* What a module runs when it cannot be provisioned: the reason on its console, the failed state
       on its LEDs, and it waits for the operator (resources/views/scriptexecute/failed.blade.php) */
    protected function failureScript(Request $req, $message)
    {
        return response()->view('scriptexecute.failed', [
            'message' => $message,
            'server' => $this->moduleServer($req),
            'serial' => $this->serial,
            'storage' => $this->cm?->provisioning_storage ?: '/dev/mmcblk0',
            'diagnosticsToken' => $this->cm?->diagnostics_token,
        ])->header('Content-Type', 'text/plain');
    }

    public function startProvisoning(Request $req)
    {
        return ModuleOperationLock::run([$this->serial], fn () => $this->startLockedProvisioning($req));
    }

    protected function startLockedProvisioning(Request $req)
    {
        if ($reason = ReprovisionIntent::guard($this->serial, Project::getActive(), $req->query('mac')))
            return $this->failureScript($req, $reason);
        if (ReprovisionOperation::pending($this->serial))
            Cm::where('serial', $this->serial)->update(['durable_bytes' => null, 'durable_at' => null, 'durable_image_id' => null]);
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

        /* The image resume (2.6.1): a module that stopped in its write goes on from its last durable piece, on the storage
           that write was on */
        if ($previous && $project && $this->sameStorage($previous, $req) && ($from = $previous->resumableFrom($project)) !== null)
            return $this->resumeProvisioning($req, $previous, $project, $from);

        $bootmode = $req->query('bootmode');
        // with switches the board is looked up in the background (ResolveBoards): the module gets its script at once
        $lookUp = $req->query('mac') && EthernetSwitch::exists();
        // with "Split the image into pieces" the module writes the pieces copy. One whose file is gone (a restore without the
        // uploads) is made again in the background: saving the project makes it in the first place, and a failed one waits
        // for Retry on the Images page. Queueing it must not fail the start.
        $split = $project && $project->split_image && $image && $image->splitReady();
        if ($project && $project->split_image && $image && $image->split_filename && !$split)
        {
            try
            {
                $image->queueSplit();
            }
            catch (\Throwable $e)
            {
                report($e);
            }
        }

        $this->cm = Cm::updateOrCreate(['serial' => $this->serial], array_merge($this->moduleFields($req, $lookUp), [
            'serial' => $this->serial,
            'firmware' => $project ? $project->eeprom_firmware : null,
            'diagnostics_token' => Str::random(40),
            'storage_diagnostics' => null,
            'storage_diagnostics_at' => null,
            'diagnostics_requested_at' => null,
            'pre_script_output' => null,
            'post_script_output' => null,
            'script_return_code' => null,
            'temp2' => null,
            'project_id' => $project ? $project->id : null,
            'provisioning_storage' => $project?->storage,
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
            'durable_at' => null,
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

        $msg = "Provisioning started. Utility OS: ".$this->cm->utilityOsLabel().".";
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
            /* as at any start: booted over USB, the module would only boot over USB again after the write */
            'os_check' => $project->os_check ? ($req->query('bootmode') == 3 ? 'skipped' : 'pending') : null,
        ]));
        $this->cm->resumeWrite(ImagePieces::resumedDetail($from, $total), $from);
        $batch = NotificationBatch::current($project);
        $batch->touchEvent();
        $this->cm->notification_batch_id = $batch->id;
        $this->cm->save();
        $this->logInfo('Provisioning resumed: the module restarted during the write; continuing from '
            .ImagePieces::gb($from).' of '.ImagePieces::gb($total).'.'
            .' Utility OS: '.$this->cm->utilityOsLabel().'.'
            .($this->cm->os_check === 'skipped' ? ' OS check skipped: the module booted over USB.' : ''));

        $this->lookUpBoardLater();
        return $this->moduleScript($req, $project, collect(), $this->postinstallScripts($project, $image),
            $this->writeData($this->moduleServer($req), $image, true, $from), true);
    }

    /* The module starts with the storage its last run wrote: the same CID and size, where the module tells them (a CM4 Lite
       may get another SD card) */
    protected function sameStorage(Cm $previous, Request $req): bool
    {
        $cid = (string) $req->query('cid');
        $size = $req->query('storagesize') ? $req->query('storagesize') * 512 : null;
        return (!$previous->provisioning_storage || $previous->provisioning_storage === Project::getActive()?->storage)
            && ($cid === '' || !$previous->cid || $previous->cid === $cid)
            && (!$size || !$previous->storage || (int) $previous->storage === (int) $size);
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
            'utility_os' => self::utilityOsOf($req),
            'provisioning_board' => $board,
            'switch_id' => null,
            'switch_name' => null,
            'switch_port' => null,
            'board_pending_since' => $lookUp ? now() : null,
        ];
    }

    /* Ours sends uos=<version>; the legacy utility OS sends nothing. */
    protected static function utilityOsOf(Request $req): ?string
    {
        $uos = $req->query()['uos'] ?? null;
        return is_string($uos) && preg_match('/^[0-9A-Za-z.-]{1,32}$/D', $uos) ? $uos : null;
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
        if ($project->reprovision && $image)
        {
            $agent = new Script;
            $agent->id = -1;
            $agent->name = 'Re-provision agent';
            $agent->bg = false;
            try {
                $agent->script = app(ReprovisionAgent::class)->install($project->storage, app(ProvisionerKey::class)->ensure());
            } catch (\RuntimeException|\InvalidArgumentException $e) {
                $agent->script = "#!/bin/sh\nprintf '%s\\n' ".escapeshellarg('agent skipped: '.$e->getMessage())."\nexit 0\n";
            }
            $postinstall_scripts->push($agent);
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

        if (!$this->cm->diagnostics_token)
        {
            $this->cm->diagnostics_token = Str::random(40);
            $this->cm->save();
        }
        $response = response()->view('scriptexecute', array_merge($write, [
            'cm' => $this->cm,
            'diagnosticsToken' => $this->cm->diagnostics_token,
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
                'scripts' => $firstboot_scripts, 'packages' => $packages,
                'identify_run' => (string) $this->cm->provisioning_started_at->getTimestamp()])->render() : null,
            'oscheck_install' => $osCheck ? view('scriptexecute.oscheck-install')->render() : null,
        ]))->header('Content-Type', 'text/plain');
        ReprovisionIntent::started($this->cm);
        return $response;
    }

    public function provisioningComplete(Request $req)
    {
        $this->cm = Cm::where('serial', $this->serial)->firstOrFail();
        $project = $this->cm->project;
        if ($this->cm->os_check === 'pending')
            return $this->rebootIntoTheNewSystem($req, $project);
        if ($this->cm->os_check === 'waiting')
            return '';   // report() sent "written" again: the run waits for the new system all the same
        $this->cm->provisioning_complete_at = now();
        $this->cm->temp2 = $req->query('temp');
        $this->cm->setPhase('done');
        $this->cm->progress_bytes = $this->cm->progress_total;
        $this->cm->save();
        $this->touchBatch();

        $msg = 'Provisioning completed.';
        if ($req->query('verify'))
        {
            $msg .= " Verification successful.";
        }
        $printLabel = $project && $project->label_moment == 'postinstall' && $project->label;
        if ($printLabel)
        {
            $msg .= " Printing label.";
        }
        $this->logInfo($msg);

        if ($printLabel)
        {
            $this->printLabel();
        }

        // Emit event
        CmProvisioningComplete::dispatch($this->cm);

        return "";
    }

    /* The image is written and the OS check is due: the module reboots into its new system, whose service reports
       (registerFirstBoot()); FirstBootTimeout fails the run when nothing comes. A label "after writing" prints now. */
    protected function rebootIntoTheNewSystem(Request $req, $project)
    {
        $this->cm->temp2 = $req->query('temp');
        $this->cm->os_check = 'waiting';
        $this->cm->setPhase('firstboot');
        $this->cm->save();
        $this->touchBatch();

        $msg = 'Image written.';
        if ($req->query('verify'))
            $msg .= ' Verification successful.';
        $printLabel = $project && $project->label_moment == 'postinstall' && $project->label;
        if ($printLabel)
            $msg .= ' Printing label.';
        $this->logInfo($msg.' Rebooting into the new system to check it.');
        if ($printLabel)
            $this->printLabel();

        try
        {
            FirstBootTimeout::dispatch($this->cm->id, $this->cm->provisioning_started_at->getTimestamp())
                ->delay(now()->addSeconds(Cm::FIRSTBOOT_SECONDS));
        }
        catch (\Throwable $e)
        {
            report($e);   // the run waits until the module reports or comes back
        }
        return '';
    }

    /* ?firstboot=1: the OS check's report from the new system (resources/views/scriptexecute/firstboot.blade.php) with
       its log, host name, systemd state and failed units; retcode 0 passes the check. A report nobody waits for (the
       service ran again after a reboot of its own) changes nothing. */
    public function registerFirstBoot(Request $req)
    {
        $this->cm = Cm::where('serial', $this->serial)->first();
        if (!$this->cm || !$this->cm->awaitsOsReport())
            return '';

        $log = $req->hasFile('log') ? $req->file('log')->get() : '';
        if (strlen($log) > self::MAX_LOG_SIZE)
            $log = substr($log, 0, self::MAX_LOG_SIZE)."\nLog was bigger than max allowed size. Truncated.";
        $host = substr(preg_replace('/[^A-Za-z0-9.-]/', '', (string) $req->input('hostname')), 0, 64);
        $state = substr(preg_replace('/[^a-z-]/', '', (string) $req->input('state')), 0, 20);
        $units = trim(preg_replace('/\s+/', ' ', (string) $req->input('failed_units')));
        $this->cm->hostname = $host !== '' ? $host : null;
        $this->cm->os_state = $state !== '' ? $state : null;
        $this->cm->os_failed_units = $units !== '' ? Str::limit($units, 2000, '') : null;
        $this->cm->os_checked_at = now();
        $this->cm->os_check_output = $log;

        if ((string) $req->query('retcode') === '0')
        {
            $this->cm->os_check = 'passed';
            $this->cm->provisioning_complete_at = now();
            $this->cm->setPhase('done');
            $this->cm->save();
            $this->touchBatch();
            $project = $this->cm->project;
            $printLabel = $project && $project->label_moment === 'firstboot' && $project->label;
            $this->logInfo('OS check passed'.$this->osAbout().'.'.($printLabel ? ' Printing label.' : '')."\n\n".$log);
            if ($printLabel)
            {
                try
                {
                    PrintLabel::dispatch($this->cm->id);   // the report does not wait for the printer
                }
                catch (\Throwable $e)
                {
                    report($e);
                }
            }
            CmProvisioningComplete::dispatch($this->cm);
            return '';
        }

        $reason = Str::limit(trim((string) $req->input('reason')), 200, '');
        $msg = 'OS check failed: '.($reason !== '' ? $reason : 'the new system reported a failure');
        $this->cm->os_check = 'failed';
        $this->logInfo($msg.$this->osAbout().".\n\n".$log, 'error');
        $this->cm->markFailed($msg)->save();
        $this->failed();
        return '';
    }

    /* The waiting run of the OS check ends without a report: failed with $reason, the notification goes out */
    protected function closeTheCheck($reason)
    {
        $this->cm->os_check = 'failed';
        $this->logInfo($reason, 'error');
        $this->cm->markFailed($reason)->save();
        $this->failed();
    }

    /* " on wlanpi-a1b (systemd: degraded; failed: firefox-setup.service)" for the log */
    protected function osAbout()
    {
        $about = $this->cm->hostname ? ' on '.$this->cm->hostname : '';
        $parts = [];
        if ($this->cm->os_state)
            $parts[] = 'systemd: '.$this->cm->os_state;
        if ($this->cm->os_failed_units)
            $parts[] = 'failed: '.$this->cm->os_failed_units;
        return $about.($parts ? ' ('.implode('; ', $parts).')' : '');
    }

    public function registerLogFile(Request $req)
    {
        $this->cm = Cm::where('serial', $this->serial)->firstOrFail();
        $logfile = $req->file('log')->get();
        if (strlen($logfile) > self::MAX_LOG_SIZE)
            $logfile = substr($logfile, 0, self::MAX_LOG_SIZE)."\nLog was bigger than max allowed size. Truncated.";
        $phase   = $req->query("phase");
        $retcode = $req->query("retcode");
        $this->cm->script_return_code = $retcode;

        if ($phase == "preinstall")
        {
            $this->cm->pre_script_output = $logfile;
            if (preg_match(self::EEPROM_RESULT_LINE, $logfile, $m))
                $this->cm->eeprom_result = $m[1];
            else if ($retcode && $this->cm->eeprom_config_after !== null)
                $this->cm->eeprom_result = 'failed';   // the flash script runs first and did not get to its result
        }
        else if ($phase == "postinstall")
        {
            $this->cm->post_script_output = $logfile;
        }

        if ($retcode)
        {
            if ($phase == "dd" || $phase == "preinstall" || $phase == "storage") {
                /* Failed before or during image write. Clear image fields in database */
                $this->cm->image_filename = null;
                $this->cm->image_sha256 = null;
            }

            $msg = "Error during $phase. Return code $retcode.";
            if ($phase == "dd" && ($diagnosis = self::diagnoseWriteFailure($logfile)))
            {
                $msg .= " Diagnosis: $diagnosis.";
            }
            if ($phase == "dd" && str_contains($logfile, self::STORAGE_WRITTEN))
            {
                /* the image resume (2.6.1): what the marks say was written is no longer there */
                $this->cm->durable_bytes = null;
                $this->cm->durable_at = null;
            }
            $this->logInfo($msg." Script output:\n\n".$logfile, 'error');
            $this->cm->markFailed($msg);
            $failed = true;
        }
        else
        {
            if ($phase == "preinstall")
            {
                $msg = "Preinstall script complete.";
                $hasImage = $this->cm->project && $this->cm->project->image;
                if ($hasImage)
                {
                    $msg .= " Starting to write image.";
                }

                $this->logInfo($msg);
                $this->cm->setPhase($hasImage ? 'write' : 'postinstall');
            }
        }

        $this->cm->save();
        if (!empty($failed))
            $this->failed();
        return "";
    }


    /* The bootloader the module runs before anything is flashed: version, and the settings unless
       the version was read from the flash chip */
    public function registerFirmware(Request $req)
    {
        $this->cm = Cm::where('serial', $this->serial)->firstOrFail();
        $version = $req->file('eeprom_version')->get();

        $regs = [];
        if (preg_match("/BUILD_TIMESTAMP=([0-9]+)/", $version, $regs) )
        {
            /* If we only have a BUILD_TIMESTAMP, also convert it to a human friendly date/time string */
            $version .= date('r', $regs[1]);
        }
        $this->cm->eeprom_before = $version;

        /* vcgencmd answers "error=... error_msg=..." for a command the firmware does not know */
        $config = $req->hasFile('eeprom_config') ? $req->file('eeprom_config')->get() : '';
        $this->cm->eeprom_config_before = (trim($config) === '' || Str::contains($config, 'error_msg=')) ? null : $config;

        /* A project that does not flash the EEPROM leaves it as it was */
        if ($this->cm->eeprom_config_after === null)
            $this->cm->firmware = $version;
        $this->cm->save();
    }

    /* ?identify=poll: a module that finished asks every few seconds whether the operator wants it to
       blink. No log entry; the module is noted as on the bench (polled_at) at most once a minute. */
    public function identifyPoll(Request $req)
    {
        $this->cm = Cm::where('serial', $this->serial)->first();
        if (!$this->cm)
            return '';

        // New diagnostic-capable loops carry their run token; a previous run cannot revive this one.
        if ($req->query->has('diagnostics_token') && (!is_string($req->query('diagnostics_token'))
            || !$this->cm->diagnostics_token || !hash_equals($this->cm->diagnostics_token, $req->query('diagnostics_token'))))
            return '';

        // New-system loops belong to one run. Older utility OS scripts have no run parameter.
        if ($req->query->has('identify_run') && $req->query('identify_run') !== (string) $this->cm->provisioning_started_at?->getTimestamp())
            return '';

        if (!$this->cm->polled_at || $this->cm->polled_at->getTimestamp() <= now()->getTimestamp() - Cm::POLL_NOTE_SECONDS)
        {
            $this->cm->polled_at = now();
            $this->cm->save();
        }
        if ($this->cm->isIdentifying())
            return 'identify';
        if ($req->query->has('diagnostics_token') && $this->cm->canCollectModuleDiagnostics()
            && $this->cm->diagnostics_requested_at?->gt(now()->subSeconds(ModuleDiagnostics::REQUEST_SECONDS)))
            return 'diagnostics';
        return '';
    }

    /* A read-only snapshot from the current utility-OS script. The per-run token rejects late reports. */
    public function registerModuleDiagnostics(Request $req)
    {
        abort_unless($req->isMethod('POST'), 405);
        return ModuleOperationLock::run([$this->serial], function () use ($req) {
            $cm = Cm::where('serial', $this->serial)->firstOrFail();
            $token = $req->input('diagnostics_token');
            abort_unless(is_string($token) && $cm->diagnostics_token && hash_equals($cm->diagnostics_token, $token), 403);
            $file = $req->file('diagnostics');
            abort_unless($file instanceof \Illuminate\Http\UploadedFile && $file->isValid(), 422);
            abort_if($file->getSize() > ModuleDiagnostics::MAX_BYTES, 413);
            try
            {
                $report = ModuleDiagnostics::parse($file->get(), $cm);
            }
            catch (\InvalidArgumentException $e)
            {
                abort(422, $e->getMessage());
            }
            $previousReport = $cm->storage_diagnostics;
            $summary = ModuleDiagnostics::summary($report);
            if ($report['context'] === 'failure' && $summary['issues'] && $previousReport !== $report)
            {
                foreach ($summary['issues'] as $issue)
                    Cmlog::create(['cm' => $cm->serial, 'board' => $cm->provisioning_board, 'ip' => $req->ip(),
                        'loglevel' => 'error', 'msg' => 'Module diagnostics: '.$issue['title'].'. '.
                            ($issue['evidence'] ? $issue['evidence']."\n" : '').$issue['advice']]);
            }
            $cm->storage_diagnostics = $report;
            $cm->storage_diagnostics_at = now();
            $cm->diagnostics_requested_at = null;
            $cm->save();
            return response('', 204);
        });
    }

    /* ?progress=<phase>[&detail=<script name>][&sectors=<n>]: where the module is, sent every few seconds
       while the image is written or verified. Best effort on both sides, so never an error and no log entry. */
    public function registerProgress(Request $req)
    {
        $phase = $req->query('progress');
        if (!in_array($phase, self::PROGRESS_PHASES, true))
            return '';

        $this->cm = Cm::where('serial', $this->serial)->first();
        if (!$this->cm || !$this->cm->isActive())
            return '';   // unknown module, or a late report after completion or failure
        if ($this->cm->phase === 'firstboot')
            return '';   // a late mark of the utility OS: the module reboots into its new system

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

        $this->cm->setPhase($phase, $req->query('detail'));
        $sectors = $req->query('sectors');
        if (is_string($sectors) && ctype_digit($sectors))
            $this->cm->progress_bytes = (int) $sectors * 512;
        /* the image resume (2.6.1): how far a run on the pieces copy is written for sure, in MiB (write_pieces): only up, and
           only where a piece of the image starts, so before its end */
        $durable = $req->query('durable_mb');
        if (is_string($durable) && ctype_digit($durable) && strlen($durable) < 10 && $this->cm->durable_image_id
            && $this->cm->phase === 'write' && (int) $durable * 1048576 > (int) $this->cm->durable_bytes
            && (int) $durable * 1048576 < (int) $this->cm->progress_total)
        {
            $this->cm->durable_bytes = (int) $durable * 1048576;
            $this->cm->durable_at = now();
        }
        $this->cm->save();

        return '';
    }

    /* Do not provision: tell the web log, mark the module failed, show the reason on its console and
       the failed state on its LEDs */
    protected function refuse($reason, $consoleMessage = null)
    {
        $this->logInfo($reason, 'error');
        if ($this->cm)
        {
            $this->cm->markFailed($reason)->save();
            $this->failed();
        }
        $this->lookUpBoardLater();

        return $this->failureScript(request(), $consoleMessage ?: $reason);
    }

    /* The module's provisioning stopped: keep its batch alive and tell the notification channels */
    protected function failed()
    {
        $this->touchBatch();
        CmProvisioningFailed::dispatch($this->cm);
    }

    protected function touchBatch()
    {
        if ($this->cm && $this->cm->notification_batch_id)
            NotificationBatch::whereKey($this->cm->notification_batch_id)->update(['last_event_at' => now()]);
    }

    /* Explanation of a failed image write from the log the module sent, or null when nothing is recognised */
    public static function diagnoseWriteFailure($log)
    {
        foreach (self::WRITE_FAILURE_HINTS as $pattern => $hint)
        {
            if (preg_match($pattern, $log))
                return $hint;
        }
        return null;
    }

    public function logInfo($msg, $loglevel = 'info')
    {
        Cmlog::create([
            'cm' => $this->serial,
            'board' => $this->cm ? $this->cm->provisioning_board : null,
            'loglevel' => $loglevel,
            'ip' => request()->ip(),
            'msg' => $msg
        ]);
    }

    public function fatal($msg)
    {
        $this->logInfo($msg, 'error');
        abort(500);
    }

    public function printLabel()
    {
        LabelPrinter::print($this->cm, request()->ip());
    }

    /* The module's board, when its start waits for one: queued once the start is decided, refused or not, so the job
       knows a refused module as one. A queueing error must not fail the start: the next run finds the module anyway */
    protected function lookUpBoardLater()
    {
        if (!$this->cm || !$this->cm->board_pending_since)
            return;
        try
        {
            ResolveBoards::dispatch();
        }
        catch (\Throwable $e)
        {
            report($e);
        }
    }
}
