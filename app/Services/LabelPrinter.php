<?php

namespace App\Services;

use App\Models\Cm;
use App\Models\Cmlog;

/* A module's label as its project prints it: FTP to the printer, or a command with the label in a file. What goes
   wrong, or what the command says, lands in the module's log; $ip is the module's address when it asked */
class LabelPrinter
{
    public static function print(Cm $cm, ?string $ip = null): void
    {
        $labelsettings = $cm->project ? $cm->project->label : null;
        if (!$labelsettings)
            return;
        $label = str_replace('$mac', $cm->mac, $labelsettings->template);
        $label = str_replace('$serial', $cm->serial, $label);
        // the host name the new system reported (the OS check, 2.6.0); empty before it
        $label = str_replace('$hostname', (string) $cm->hostname, $label);
        // the board ("sw01 Gi0/3") and its two halves; empty without a switch
        $label = str_replace(['$provisionboard', '$switch', '$port'],
            [(string) $cm->provisioning_board, (string) $cm->switch_name, (string) $cm->switch_port], $label);
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
                if (!@ftp_put($ftp, "label-".$cm->serial.".".$labelsettings->file_extension, $tmpfile))
                    throw new \Exception("Error uploading file to printer's FTP server");
                @ftp_close($ftp);
            }
            else if ($labelsettings->printer_type == 'command')
            {
                $cmd = str_replace('$file', escapeshellarg($tmpfile), $labelsettings->print_command);
                $output = $retcode = null;
                // stderr too: sh reports a file it cannot write there; the newline survives a trailing comment
                if (@exec('{ '.$cmd."\n} 2>&1", $output, $retcode) === false)
                    throw new \Exception("Error executing '$cmd'");
                if ($retcode)
                    throw new \Exception("Executing '$cmd' returned exit code $retcode. Program output:\n".implode("\n", $output));
                // a command can report trouble and still exit 0 (a CSV it cannot write): keep what it said
                $said = trim(implode("\n", $output));
                if ($said !== '')
                    self::log($cm, $ip, 'info', 'Label command output: '.$said);
            }
        }
        catch (\Exception $e)
        {
            self::log($cm, $ip, 'error', $e->getMessage());
        }
        @unlink($tmpfile);
    }

    protected static function log(Cm $cm, ?string $ip, string $level, string $msg): void
    {
        Cmlog::create(['cm' => $cm->mac, 'board' => $cm->provisioning_board, 'loglevel' => $level, 'ip' => $ip, 'msg' => $msg]);
    }
}
