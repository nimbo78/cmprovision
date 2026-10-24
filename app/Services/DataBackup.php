<?php

namespace App\Services;

use App\Support\AppVersion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use SQLite3;
use Symfony\Component\Process\Process;

/**
 * A copy of the data of this server to keep somewhere else (2.20.0, Settings → Status → Backup): the database (a
 * consistent copy by the SQLite backup API, the database is in WAL), .env (the application key), the firmware store with
 * the SSH key and the other files of storage/app, and the dnsmasq config. The uploaded images and packages are not in it
 * (public/uploads: gigabytes), the system config is not either: it is what `cmprovision-restore --data-only` puts back.
 *
 * The file is a tar of one folder named like the snapshots of cmprovision-backup, <date>-<time>-<version>, holding the
 * same two files (database.sqlite, app.tar.gz), so unpacking it gives a folder `cmprovision-restore --data-only` takes.
 * It is made as the web user from what that user may read, in a folder of its own under storage/framework (not in
 * storage/app, which the copy itself holds), and removed after the download. It holds secrets: the caller asks for the
 * password first.
 */
class DataBackup
{
    const WORK_DIR = 'framework/backup';
    const STALE_MINUTES = 60;           // a folder of a download that never got to its end goes after this
    const LOCK_SECONDS = 600;
    const MEMBERS = ['.env', 'etc/dnsmasq.conf', 'storage/app'];
    const EXCLUDE = ['storage/app/upload-tmp'];   // what PHP keeps of uploads in progress
    const SPARE_BYTES = 64 * 1048576;

    protected $app, $database, $work, $free;

    /* All four are for the tests: the application folder (base_path()), the database file of the default connection,
       the folder to work in, and how much room there is (a function of that folder, null: disk_free_space) */
    public function __construct(?string $app = null, ?string $database = null, ?string $work = null, ?callable $free = null)
    {
        $this->app = rtrim($app ?? base_path(), '/');
        $this->database = $database ?? (string) config('database.connections.'.config('database.default').'.database');
        $this->work = rtrim($work ?? storage_path(self::WORK_DIR), '/');
        $this->free = $free;
    }

    /* Make the file: ['path' => the tar, 'name' => the name to offer it under, 'folder' => what to remove when it is sent,
       'bytes' => its size]. Throws BackupFailed with a text for the operator when it cannot be made. */
    public function make(): array
    {
        $lock = Cache::lock('data-backup', self::LOCK_SECONDS);
        if (!$lock->get())
            throw new BackupFailed('A backup is being made by someone else. Try again in a minute.');
        try
        {
            return $this->build();
        }
        finally
        {
            $lock->release();
        }
    }

    protected function build(): array
    {
        $database = $this->databaseFile();
        $members = $this->members();
        $this->sweep();
        $this->checkRoom($database, $members);

        $stamp = now()->local()->format('Ymd-His');
        $version = preg_replace('/[^0-9A-Za-z.+~-]/', '', explode(' ', AppVersion::text())[0]) ?: 'dev';
        $snapshot = $stamp.'-'.$version;
        $folder = $this->work.'/'.bin2hex(random_bytes(8));
        if (!@mkdir($folder.'/'.$snapshot, 0700, true) && !is_dir($folder.'/'.$snapshot))
            throw new BackupFailed('Could not make a folder in '.$this->work.'.');
        try
        {
            $this->copyDatabase($database, $folder.'/'.$snapshot.'/database.sqlite');
            $this->tar(array_merge(['czf', $folder.'/'.$snapshot.'/app.tar.gz', '--warning=no-file-changed'],
                array_map(function ($path) { return '--exclude='.$this->member($path); }, self::EXCLUDE),
                ['-C', '/'], array_map([$this, 'member'], $members)));
            $name = 'cmprovision-backup-'.(preg_replace('/[^0-9A-Za-z.-]/', '', (string) gethostname()) ?: 'server').'-'.$snapshot.'.tar';
            $this->tar(['cf', $folder.'/'.$name, '-C', $folder, $snapshot]);
            // what the file holds is in the tar now
            File::deleteDirectory($folder.'/'.$snapshot);
        }
        catch (\Throwable $e)
        {
            File::deleteDirectory($folder);
            throw $e;
        }
        return ['path' => $folder.'/'.$name, 'name' => $name, 'folder' => $folder, 'bytes' => (int) filesize($folder.'/'.$name)];
    }

    /* Remove the folder of a download (the file was sent or the sending was dropped) */
    public function forget(string $folder): void
    {
        if (str_starts_with($folder, $this->work.'/') && basename($folder) !== '')
            File::deleteDirectory($folder);
    }

    /* The folders a download left behind, say because the web server was restarted while it was sent */
    protected function sweep(): void
    {
        if (!is_dir($this->work))
        {
            @mkdir($this->work, 0700, true);
            return;
        }
        foreach (glob($this->work.'/*', GLOB_ONLYDIR) ?: [] as $old)
            if (filemtime($old) < now()->subMinutes(self::STALE_MINUTES)->getTimestamp())
                File::deleteDirectory($old);
    }

    protected function databaseFile(): string
    {
        if ($this->database === '' || $this->database === ':memory:' || !is_file($this->database))
            throw new BackupFailed('The database of this server is not a file that can be copied.');
        return $this->database;
    }

    /* The files and folders of the application that are there; .env is the one that has to be */
    protected function members(): array
    {
        if (!is_file($this->app.'/.env'))
            throw new BackupFailed('There is no .env in '.$this->app.': this does not look like an installed server.');
        return array_values(array_filter(self::MEMBERS, function ($path) { return file_exists($this->app.'/'.$path); }));
    }

    /* The name tar stores a path of the application under: the absolute path without its first slash, as cmprovision-backup's */
    protected function member(string $path): string
    {
        return ltrim($this->app.'/'.$path, '/');
    }

    /* The copy takes about the size of what it holds, the final file once more, and some room is left for the system */
    protected function checkRoom(string $database, array $members): void
    {
        $bytes = filesize($database);
        foreach ($members as $path)
            $bytes += $this->size($this->app.'/'.$path);
        if (!is_dir($this->work))
            @mkdir($this->work, 0700, true);
        $free = $this->free ? ($this->free)($this->work) : @disk_free_space($this->work);
        if ($free !== false && $free !== null && $free < 2 * $bytes + self::SPARE_BYTES)
            throw new BackupFailed(sprintf('Not enough room to make the copy: %d MB are needed and %d MB are free in %s.',
                ceil((2 * $bytes + self::SPARE_BYTES) / 1048576), floor($free / 1048576), $this->work));
    }

    protected function size(string $path): int
    {
        if (is_file($path))
            return (int) filesize($path);
        $bytes = 0;
        if (is_dir($path))
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $file)
                if ($file->isFile())
                    $bytes += $file->getSize();
        return $bytes;
    }

    /* A consistent copy of a database that is being written (the backup API, as `sqlite3 .backup` in cmprovision-backup) */
    protected function copyDatabase(string $from, string $to): void
    {
        $source = $target = null;
        try
        {
            $source = new SQLite3($from, SQLITE3_OPEN_READWRITE);
            $source->busyTimeout(10000);
            $target = new SQLite3($to);
            if (!$source->backup($target))
                throw new BackupFailed('The database could not be copied.');
        }
        catch (BackupFailed $e)
        {
            throw $e;
        }
        catch (\Throwable $e)
        {
            throw new BackupFailed('The database could not be copied: '.$e->getMessage());
        }
        finally
        {
            if ($target)
                $target->close();
            if ($source)
                $source->close();
        }
        @chmod($to, 0600);
    }

    /* tar with the arguments as they are (no shell); 1 is "a file changed while it was read", which a log or a cache may do */
    protected function tar(array $arguments): void
    {
        $process = new Process(array_merge(['tar'], $arguments));
        $process->setTimeout(900);
        $process->run();
        if ($process->getExitCode() > 1)
            throw new BackupFailed('tar failed: '.trim(substr($process->getErrorOutput(), 0, 300)));
    }
}
