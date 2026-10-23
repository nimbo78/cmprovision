<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Fills the local EEPROM firmware store with the bootloader images Raspberry Pi publishes
 * for BCM2711 (Pi 4, CM4): only the files that are missing locally are fetched, and local
 * files are never removed, so a project stays pinned to the image it was configured with.
 *
 * Sources, in order: the rpi-eeprom Debian package (works offline), then GitHub.
 */
class FirmwareUpdater
{
    const GITHUB_CONTENTS = 'https://api.github.com/repos/raspberrypi/rpi-eeprom/contents/firmware-2711';
    const NOTES_URL = 'https://raw.githubusercontent.com/raspberrypi/rpi-eeprom/master/firmware-2711/release-notes.md';
    const NOTES_MAX_BYTES = 2097152;
    const CHANNELS = ['default', 'latest'];
    const IMAGE_PATTERN = '/^pieeprom-.+\.bin$/';

    protected $dir, $systemDir;

    public function __construct($dir = null, $systemDir = null)
    {
        $this->dir = $dir ?: config('cmprovision.firmware_dir');
        $this->systemDir = $systemDir ?: config('cmprovision.system_firmware_dir');
    }

    /**
     * @return array{added: string[], errors: string[], notes: string}  paths relative to the store, what went wrong,
     *         and what became of the release notes: 'updated', 'unchanged' or 'unavailable' (not an error: they only inform)
     */
    public function update()
    {
        $result = ['added' => [], 'errors' => [], 'notes' => 'unavailable'];

        foreach (self::CHANNELS as $channel)
        {
            $this->importFromSystemPackage($channel, $result);
            $this->downloadFromGithub($channel, $result);
        }
        $result['notes'] = $this->updateNotes();

        return $result;
    }

    /**
     * Keeps the newest release notes beside the images: the package's copy (works offline) or GitHub's, whichever
     * knows the later release; the stored file is replaced only by notes that reach at least as far as it does.
     */
    protected function updateNotes(): string
    {
        $candidates = [];   // text => newest date, GitHub's last so that on a tie its text is the one kept
        $package = @file_get_contents($this->systemDir.'/'.FirmwareNotes::FILE);
        if ($package !== false)
            $candidates[] = $package;
        try
        {
            $download = Http::withHeaders(['User-Agent' => 'cmprovision'])->timeout(30)->get(self::NOTES_URL);
            if ($download->ok() && strlen($download->body()) <= self::NOTES_MAX_BYTES)
                $candidates[] = $download->body();
        }
        catch (\Throwable $e)
        {
            // the notes only inform: no network is no failure
        }

        $target = $this->dir.'/'.FirmwareNotes::FILE;
        $stored = new FirmwareNotes($target);
        $best = null;
        $bestDate = $stored->latestDate() ?? '';
        $found = false;
        foreach ($candidates as $text)
        {
            $entries = FirmwareNotes::parse($text);
            if (!$entries)
                continue;
            $found = true;
            $date = max(array_column($entries, 'date'));
            if ($date >= $bestDate)
            {
                $best = $text;
                $bestDate = $date;
            }
        }
        if ($best === null)
            return $found || $stored->latestDate() !== null ? 'unchanged' : 'unavailable';
        if (@file_get_contents($target) === $best)
            return 'unchanged';

        $this->store(FirmwareNotes::FILE, $best);
        return 'updated';
    }

    protected function importFromSystemPackage($channel, array &$result)
    {
        foreach (glob($this->systemDir.'/'.$channel.'/pieeprom-*.bin') ?: [] as $file)
        {
            $relative = $channel.'/'.basename($file);
            if (!preg_match(self::IMAGE_PATTERN, basename($file)) || $this->has($relative))
                continue;

            $this->store($relative, file_get_contents($file));
            $result['added'][] = $relative;
        }
    }

    protected function downloadFromGithub($channel, array &$result)
    {
        $listing = Http::withHeaders(['User-Agent' => 'cmprovision', 'Accept' => 'application/vnd.github+json'])
            ->timeout(30)
            ->get(self::GITHUB_CONTENTS.'/'.$channel);
        if (!$listing->ok())
        {
            $result['errors'][] = "GitHub listing of $channel failed: HTTP ".$listing->status();
            return;
        }

        foreach ($listing->json() as $entry)
        {
            if (($entry['type'] ?? '') != 'file' || !preg_match(self::IMAGE_PATTERN, $entry['name']))
                continue;

            $relative = $channel.'/'.$entry['name'];
            if ($this->has($relative))
                continue;

            $download = Http::withHeaders(['User-Agent' => 'cmprovision'])->timeout(120)->get($entry['download_url']);
            if (!$download->ok())
            {
                $result['errors'][] = "Download of $relative failed: HTTP ".$download->status();
                continue;
            }

            $content = $download->body();
            /* The listing carries the git blob id of each file, so a truncated or tampered download is detectable */
            if (self::gitBlobSha1($content) != $entry['sha'])
            {
                $result['errors'][] = "Download of $relative failed: checksum mismatch";
                continue;
            }

            $this->store($relative, $content);
            $result['added'][] = $relative;
        }
    }

    protected function has($relative)
    {
        return is_file($this->dir.'/'.$relative);
    }

    protected function store($relative, $content)
    {
        $target = $this->dir.'/'.$relative;
        $channelDir = dirname($target);

        /* The old updater extracted upstream symlinks (latest -> stable, ...) as plain files; they are in the way now */
        if (is_file($channelDir))
            unlink($channelDir);
        if (!is_dir($channelDir))
            mkdir($channelDir, 0755, true);

        $tmp = $target.'.part';
        file_put_contents($tmp, $content);
        rename($tmp, $target);
    }

    public static function gitBlobSha1($content)
    {
        return sha1("blob ".strlen($content)."\0".$content);
    }
}
