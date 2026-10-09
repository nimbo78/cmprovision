<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Events\CmProvisioningComplete;
use App\Events\CmProvisioningFailed;
use App\Events\CmProvisioningStarted;
use App\Models\Cm;
use App\Models\Cmlog;
use App\Models\NotificationBatch;
use App\Models\Project;
use App\Models\Script;
use App\Models\Setting;
use App\Services\SwitchPortFinder;

class ScriptExecuteController extends Controller
{
    public $serial, $cm;
    const MAX_LOG_SIZE = 1*1024*1024;
    const DECOMPRESSORS = ['gz' => 'gzip -dc', 'xz' => 'xz -dc', 'bz2' => 'bunzip2 -dc'];
    /* Phases a module may report with ?progress= (see progress_mark in scriptexecute.blade.php) */
    const PROGRESS_PHASES = ['preinstall', 'write', 'verify', 'postinstall'];

    /* What the output of a failed image write usually means. Pattern => explanation. */
    const WRITE_FAILURE_HINTS = [
        '/curl exit code (18|56|55|52)\b/' => 'the connection to the provisioning server was closed before the download finished (server timeout, network problem or server restart)',
        '/curl exit code (7|28)\b/'        => 'the module could not reach the provisioning server (connection refused or timed out)',
        '/curl exit code 23\b/'            => 'the decompressor stopped accepting data; see its message below',
        '/curl exit code 6\b/'             => 'the provisioning server name could not be resolved',
        '/(invalid magic|not in gzip format|File format not recognized|unsupported compression|Not a bzip2 file)/i' => 'the downloaded data is not a valid compressed image (wrong file type, or an HTTP error page instead of the image)',
        '/(unexpected end of (file|input)|corrupt|crc error|data integrity)/i' => 'the compressed image is truncated or corrupted (interrupted download or a bad upload)',
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

        try
        {
            if ($req->query("alldone"))
            {
                return $this->provisioningComplete($req);
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
                }
            }
            catch (\Throwable $ignored)
            {
            }

            return response("#!/bin/sh
echo ".escapeshellarg($msg)."
exit 1
", 200)
                ->header('Content-Type', 'text/plain');
        }
    }

    public function startProvisoning(Request $req)
    {
        $project = Project::getActive();
        $image   = $project ? $project->image : null;
        $bootmode = $req->query('bootmode');
        $jumper  = $req->query('inversejumper');
        $switchConfig = SwitchPortFinder::config();

        $switchWarning = null;
        if ($switchConfig['host'] && $req->query('mac'))
        {
            try
            {
                $finder = SwitchPortFinder::forConfig($switchConfig);
                $board = $finder->portOf($req->query('mac'));
                if ($board === null)
                {
                    $switchWarning = "Switch port unknown: ".$switchConfig['host']." does not know MAC ".$req->query('mac');
                }
                else if ($finder->lastMethod() !== $switchConfig['detected'])
                {
                    // try the method that works first next time
                    Setting::updateOrCreate(['key' => 'ethernetswitch_method_detected'], ['value' => $finder->lastMethod()]);
                }
            }
            catch (\Throwable $e)
            {
                $board = null;
                $switchWarning = "Switch port unknown: error talking to ".$switchConfig['host'].": ".$e->getMessage();
            }
        }
        else if ($jumper)
        {
            // Inverse jumper bits
            for ($i=0; $i<strlen($jumper); $i++)
                $jumper[$i] = $jumper[$i] == "0" ? "1" : "0";

            $board = $jumper.' ('.bindec($jumper).')';
        }
        else
        {
            $board = null;
        }
        $memoryInGb = null;
        if ($req->query("memorysize"))
        {
            $memoryInGb = round( ($req->query("memorysize")+200000)/1024/1024 );
        }
        $storage_bytes = $req->query('storagesize') ? $req->query('storagesize')*512 : null;

        $this->cm = Cm::updateOrCreate(['serial' => $this->serial], [
            'serial' => $this->serial,
            'mac'    => $req->query('mac') ? $req->query('mac')
                        : "b8:27:eb:".substr($this->serial, -6, 2).":".substr($this->serial, -4, 2).":".substr($this->serial, -2, 2),
            'model'  => $req->query('model'),
            'memory_in_gb' => $memoryInGb,
            'storage' => $storage_bytes,
            'firmware' => $project ? $project->eeprom_firmware : null,
            'cid' => $req->query('cid'),
            'csd' => $req->query('csd'),
            'pre_script_output' => null,
            'post_script_output' => null,
            'script_return_code' => null,
            'temp1' => $req->query('temp'),
            'temp2' => null,
            'project_id' => $project ? $project->id : null,
            'image_filename' => $image ? $image->filename : null,
            'image_sha256'   => $image ? $image->sha256 : null,
            'provisioning_board' => $board,
            'provisioning_started_at' => now(),
            'provisioning_complete_at' => null,
            'phase' => null,
            'phase_detail' => null,
            'phase_started_at' => null,
            'progress_bytes' => null,
            'progress_total' => $image ? $image->uncompressed_size : null,
            'progress_updated_at' => null,
        ]);

        if ($switchWarning)
        {
            $this->logInfo($switchWarning, 'warning');
        }

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
            if (!$storage_bytes)
            {
                return $this->refuse("Missing eMMC/SD card.");
            }
            if ($image->uncompressed_size && $storage_bytes < $image->uncompressed_size)
            {
                return $this->refuse("Image does not fit in storage. Uncompressed image size: ".$image->uncompressed_size." bytes. Available space: ".$storage_bytes." bytes.",
                                     'Image does not fit in storage.');
            }
        }

        $preinstall_scripts = $project->scripts()->where('script_type','preinstall')->orderBy('priority')->orderBy('id')->get();
        $postinstall_scripts = $project->scripts()->where('script_type','postinstall')->orderBy('priority')->orderBy('id')->get();

        $server = $req->server('HTTP_HOST');
        if ($server[0] == '[')
        {
            // From the CM's side IPv6LL address will end in %usb0
            $server = substr($server, 0, -1).'%usb0]';
        }

        if ($project->eeprom_firmware)
        {
            $setting = Setting::findOrFail('active_eeprom_sha256');
            $eeprom_url = "http://$server/uploads/pieeprom.bin";
            $eeprom_sha256 = $setting->value;
            $fscript = new Script;
            $fscript->id = 0;
            $fscript->name = 'Flash EEPROM firmware ('.$project->eeprom_firmware.')';
            $fscript->bg = false;
            $fscript->script = "#!/bin/sh\n"
                             . "set -e\n"
                             . "curl --retry 10 --silent --show-error -g -o pieeprom.bin \"$eeprom_url\"\n"
                             . "echo \"$eeprom_sha256  pieeprom.bin\" | sha256sum -c\n"
                             . 'flashrom -p "linux_spi:dev=/dev/spidev0.0,spispeed=16000" -w "pieeprom.bin"'."\n";
            $preinstall_scripts->prepend($fscript);
        }

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

        $this->cm->setPhase(count($preinstall_scripts) ? 'preinstall' : ($image ? 'write' : 'postinstall'))->save();
        CmProvisioningStarted::dispatch($this->cm);

        $msg = "Provisioning started.";
        if ($project->label_moment == 'preinstall' && $project->label)
        {
            $msg .= " Printing label.";
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

        if ($project->label_moment == 'preinstall' && $project->label)
        {
            $this->printLabel();
        }

        // Send script to client (see view in resources/view/scriptexecute.blade.php)
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

        return response()->view('scriptexecute', [
            'cm' => $this->cm,
            'project' => $project,
            'storage' => $storage,
            'part1' => $part1,
            'part2' => $part2,
            'server' => $server,
            'image_url' => $image ? "http://$server/uploads/".$image->filename_on_server : null,
            'image_extension' => $image ? $image->filename_extension : null,
            'decompress' => $image ? self::DECOMPRESSORS[$image->filename_extension] : null,
            'bootmode' => $bootmode,
            'preinstall_scripts' => $preinstall_scripts,
            'postinstall_scripts' => $postinstall_scripts
        ])->header('Content-Type', 'text/plain');
    }

    public function provisioningComplete(Request $req)
    {
        $this->cm = Cm::where('serial', $this->serial)->firstOrFail();
        $project = $this->cm->project;
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
        }
        else if ($phase == "postinstall")
        {
            $this->cm->post_script_output = $logfile;
        }

        if ($retcode)
        {
            if ($phase == "dd" || $phase == "preinstall") {
                /* Failed before or during image write. Clear image fields in database */
                $this->cm->image_filename = null;
                $this->cm->image_sha256 = null;
            }

            $msg = "Error during $phase. Return code $retcode.";
            if ($phase == "dd" && ($diagnosis = self::diagnoseWriteFailure($logfile)))
            {
                $msg .= " Diagnosis: $diagnosis.";
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


    public function registerFirmware(Request $req)
    {
        $this->cm = Cm::where('serial', $this->serial)->firstOrFail();
        $this->cm->firmware = $req->file('eeprom_version')->get();

        $regs = [];
        if (preg_match("/BUILD_TIMESTAMP=([0-9]+)/", $this->cm->firmware, $regs) )
        {
            /* If we only have a BUILD_TIMESTAMP, also convert it to a human friendly date/time string */
            $this->cm->firmware .= date('r', $regs[1]);
        }
        $this->cm->save();
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

        $this->cm->setPhase($phase, $req->query('detail'));
        $sectors = $req->query('sectors');
        if (is_string($sectors) && ctype_digit($sectors))
            $this->cm->progress_bytes = (int) $sectors * 512;
        $this->cm->save();

        return '';
    }

    /* Do not provision: tell the web log, mark the module failed and show the reason on its console */
    protected function refuse($reason, $consoleMessage = null)
    {
        $this->logInfo($reason, 'error');
        if ($this->cm)
        {
            $this->cm->markFailed($reason)->save();
            $this->failed();
        }

        return "echo ".escapeshellarg($consoleMessage ?: $reason);
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
        $labelsettings = $this->cm->project->label;
        $label = str_replace('$mac', $this->cm->mac, $labelsettings->template);
        $label = str_replace('$serial', $this->serial, $label);
        $label = str_replace('$provisionboard', $this->cm->provisioning_board, $label);
        $tmpfile = tempnam(sys_get_temp_dir(), "label-");

        try
        {
            if (!@file_put_contents($tmpfile, $label))
                throw new \Exception("Error creating temporary file for label '$tmpfile'");

            if ($labelsettings->printer_type == 'ftp')
            {
                $ftp = @ftp_connect($labelsettings->ftp_hostname);
                if (!$ftp)
                    throw new \Exception("Error connecting to printer's FTP server ".$labelsettings->ftp_hostname);
                if (!@ftp_login($ftp, $labelsettings->ftp_username, $labelsettings->ftp_password))
                    throw new \Exception("Error logging in to printer's FTP server. Check username and password");
                @ftp_pasv($ftp, true);
                if (!@ftp_put($ftp, "label-".$this->serial.".".$labelsettings->file_extension, $tmpfile))
                    throw new \Exception("Error uploading file to printer's FTP server");
                @ftp_close($ftp);
            }
            else if ($labelsettings->printer_type == 'command')
            {
                $cmd = str_replace('$file', escapeshellarg($tmpfile), $labelsettings->print_command);
                $output = $retcode = null;
                if (@exec($cmd, $output, $retcode) === false)
                    throw new \Exception("Error executing '$cmd'");
                if ($retcode)
                    throw new \Exception("Executing '$cmd' returned exit code $retcode. Program output:\n".implode("\n", $output));
            }
        }
        catch (\Exception $e)
        {
            $this->loginfo($e->getMessage(), 'error');
        }
        @unlink($tmpfile);
    }
}
