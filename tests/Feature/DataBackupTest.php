<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BackupFailed;
use App\Services\DataBackup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use App\Services\ServiceState;
use Tests\Support\FakeServiceState;
use Tests\TestCase;

/** The copy of the data to download (2.20.0): what is in it, that cmprovision-restore --data-only takes it, who may ask for it */
class DataBackupTest extends TestCase
{
    use RefreshDatabase;

    protected $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/cmp-backup-test-'.bin2hex(random_bytes(4));
        mkdir($this->root, 0700, true);
        $this->tree();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    /* An installed server in miniature: .env, firmware and the key in storage/app, a database in WAL with a row nobody has
       checkpointed yet, the uploads that are not part of the copy */
    protected function tree()
    {
        $app = $this->root.'/var/lib/cmprovision';
        foreach (['etc', 'storage/app/firmware/default', 'storage/app/ssh', 'storage/app/upload-tmp', 'storage/app/public', 'public/uploads/packages', 'database', 'storage/logs'] as $dir)
            mkdir($app.'/'.$dir, 0755, true);
        file_put_contents($app.'/.env', "APP_KEY=base64:secret\nDB_DATABASE=$app/database/database.sqlite\n");
        file_put_contents($app.'/etc/dnsmasq.conf', "dhcp-host=e4:5f:01:00:00:01,172.20.0.10\n");
        file_put_contents($app.'/storage/app/firmware/default/pieeprom-2026-09-23.bin', str_repeat('E', 2048));
        file_put_contents($app.'/storage/app/ssh/id_ed25519', 'PRIVATE KEY');
        file_put_contents($app.'/storage/app/upload-tmp/phpAbCd', 'half an upload');
        file_put_contents($app.'/storage/logs/laravel.log', 'a log');
        file_put_contents($app.'/public/uploads/image.img.gz', str_repeat('I', 4096));
        symlink($app.'/storage/app/firmware', $app.'/etc/boot');
        $this->app_ = $app;
    }

    protected $app_, $writer;

    /* a database in WAL mode with rows that are still in the -wal file (the connection that wrote them stays open) */
    protected function database(): string
    {
        $file = $this->app_.'/database/database.sqlite';
        $this->writer = new \PDO('sqlite:'.$file);
        $this->writer->exec('PRAGMA journal_mode=WAL');
        $this->writer->exec('PRAGMA wal_autocheckpoint=0');
        $this->writer->exec('create table settings (key text primary key, value text)');
        $this->writer->exec("insert into settings values ('active_project', '78'), ('telegram_token', 'TOKEN')");
        return $file;
    }

    protected function backup(?callable $free = null): DataBackup
    {
        return new DataBackup($this->app_, $this->database(), $this->root.'/work', $free);
    }

    protected function listing(string $tar, string $flags = 'tf'): array
    {
        return array_values(array_filter(explode("\n", (string) shell_exec('tar '.$flags.' '.escapeshellarg($tar).' 2>/dev/null'))));
    }

    /* the file unpacked: the folder cmprovision-restore would be given */
    protected function unpack(array $copy): string
    {
        $out = $this->root.'/unpacked';
        mkdir($out);
        shell_exec('tar xf '.escapeshellarg($copy['path']).' -C '.escapeshellarg($out));
        $folders = glob($out.'/*', GLOB_ONLYDIR);
        $this->assertCount(1, $folders, 'one folder, named as the snapshots of cmprovision-backup');
        return $folders[0];
    }

    public function test_the_file_holds_one_folder_with_the_database_and_the_files_of_the_application()
    {
        $copy = $this->backup()->make();

        $this->assertFileExists($copy['path']);
        $this->assertMatchesRegularExpression('/^cmprovision-backup-.+-\d{8}-\d{6}-.+\.tar$/', $copy['name']);
        $folder = $this->unpack($copy);
        $this->assertMatchesRegularExpression('/^\d{8}-\d{6}-[0-9A-Za-z.+~-]+$/', basename($folder), 'the name cmprovision-restore reads the version from');
        $this->assertEqualsCanonicalizing(['app.tar.gz', 'database.sqlite'], array_map('basename', glob($folder.'/*')));
        $this->assertSame(filesize($copy['path']), $copy['bytes']);

        $members = $this->listing($folder.'/app.tar.gz', 'tzf');
        $app = ltrim($this->app_, '/');
        $this->assertContains($app.'/.env', $members);
        $this->assertContains($app.'/etc/dnsmasq.conf', $members);
        $this->assertContains($app.'/storage/app/firmware/default/pieeprom-2026-09-23.bin', $members);
        $this->assertContains($app.'/storage/app/ssh/id_ed25519', $members);
    }

    public function test_what_does_not_belong_in_it_stays_out()
    {
        $folder = $this->unpack($this->backup()->make());
        $members = implode("\n", $this->listing($folder.'/app.tar.gz', 'tzf'));

        $this->assertStringNotContainsString('public/uploads', $members, 'the images and packages are gigabytes');
        $this->assertStringNotContainsString('upload-tmp', $members, 'an upload in progress is not data');
        $this->assertStringNotContainsString('storage/logs', $members);
        $this->assertStringNotContainsString('database.sqlite', $members, 'the database is the consistent copy beside the archive');
        $this->assertStringNotContainsString('etc/boot', $members, 'the link of the utility OS is made again by the package');
    }

    public function test_cmprovision_restore_data_only_would_take_every_file_it_looks_for()
    {
        // the pattern restore.sh --data-only selects with: the application folder without its first slash
        $folder = $this->unpack($this->backup()->make());
        $app = preg_quote(ltrim($this->app_, '/'), '#');
        $pattern = "#^$app/(\\.env|etc/dnsmasq\\.conf|storage/app/.+[^/]|public/uploads/.+[^/])\$#";
        $taken = array_filter($this->listing($folder.'/app.tar.gz', 'tzf'), function ($member) use ($pattern) { return preg_match($pattern, $member); });

        $this->assertNotEmpty(array_filter($taken, fn ($m) => str_ends_with($m, '/.env')), 'restore.sh refuses an archive without .env');
        $this->assertNotEmpty(array_filter($taken, fn ($m) => str_ends_with($m, 'pieeprom-2026-09-23.bin')));
        $this->assertNotEmpty(array_filter($taken, fn ($m) => str_ends_with($m, 'ssh/id_ed25519')));
        $this->assertFileExists($folder.'/database.sqlite', 'restore.sh puts this one back when it is in the folder');
    }

    public function test_the_database_copy_is_whole_although_its_newest_rows_are_still_in_the_wal_file()
    {
        $backup = $this->backup();
        $this->assertFileExists($this->app_.'/database/database.sqlite-wal');
        $folder = $this->unpack($backup->make());

        $copy = new \PDO('sqlite:'.$folder.'/database.sqlite');
        $this->assertSame(['active_project' => '78', 'telegram_token' => 'TOKEN'],
            $copy->query('select key, value from settings')->fetchAll(\PDO::FETCH_KEY_PAIR));
        $this->assertSame('ok', $copy->query('pragma integrity_check')->fetchColumn());
    }

    public function test_the_files_are_private_and_the_work_folder_is_removed_when_asked()
    {
        $backup = $this->backup();
        $copy = $backup->make();

        $this->assertSame(0700, fileperms($copy['folder']) & 0777, 'the folder of a file with secrets');
        $backup->forget($copy['folder']);
        $this->assertDirectoryDoesNotExist($copy['folder']);
        $this->assertDirectoryExists($this->root.'/work');
        // only what is under the work folder is removed
        $backup->forget($this->app_);
        $this->assertFileExists($this->app_.'/.env');
    }

    public function test_a_server_without_env_or_with_a_database_that_is_not_a_file_is_refused_with_the_reason()
    {
        unlink($this->app_.'/.env');
        try {
            $this->backup()->make();
            $this->fail('no .env');
        } catch (BackupFailed $e) {
            $this->assertStringContainsString('no .env', $e->getMessage());
        }

        file_put_contents($this->app_.'/.env', 'APP_KEY=x');
        try {
            (new DataBackup($this->app_, ':memory:', $this->root.'/work'))->make();
            $this->fail('a memory database');
        } catch (BackupFailed $e) {
            $this->assertStringContainsString('not a file', $e->getMessage());
        }
        $this->assertDirectoryDoesNotExist($this->root.'/work', 'nothing was begun');
    }

    public function test_it_is_refused_when_the_disk_has_not_the_room_for_the_copy()
    {
        try {
            $this->backup(fn () => 1024)->make();
            $this->fail('no room');
        } catch (BackupFailed $e) {
            $this->assertStringContainsString('Not enough room', $e->getMessage());
        }
        $this->assertSame([], glob($this->root.'/work/*'), 'nothing was begun');
    }

    public function test_two_copies_are_not_made_at_once_and_the_lock_is_let_go_afterwards()
    {
        $backup = $this->backup();
        $lock = Cache::lock('data-backup', 60);
        $this->assertTrue($lock->get());
        try {
            $backup->make();
            $this->fail('locked');
        } catch (BackupFailed $e) {
            $this->assertStringContainsString('someone else', $e->getMessage());
        }
        $lock->release();

        $backup->make();
        $second = $backup->make();   // the first one let the lock go
        $this->assertFileExists($second['path']);
    }

    public function test_the_folders_of_downloads_that_never_ended_are_swept_and_the_new_ones_are_not()
    {
        $backup = $this->backup();
        mkdir($this->root.'/work/old', 0700, true);
        touch($this->root.'/work/old', time() - 2 * 3600);
        mkdir($this->root.'/work/fresh', 0700, true);

        $backup->make();

        $this->assertDirectoryDoesNotExist($this->root.'/work/old');
        $this->assertDirectoryExists($this->root.'/work/fresh', 'a download that is being sent is not touched');
    }

    public function test_only_a_signed_in_user_who_has_confirmed_the_password_gets_the_file()
    {
        $this->app->instance(DataBackup::class, $this->backup());

        $this->get('/settings/backup')->assertRedirect('/login');

        $user = User::factory()->create();
        $this->actingAs($user)->get('/settings/backup')->assertRedirect('/user/confirm-password');

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->get('/settings/backup')
            ->assertOk()->assertDownload();
    }

    public function test_the_download_is_the_tar_of_the_folder_and_says_who_took_it()
    {
        Log::spy();
        $this->app->instance(DataBackup::class, $this->backup());
        $user = User::factory()->create();

        $response = $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->get('/settings/backup');

        $response->assertDownload();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'), 'a file with secrets is not kept by a cache');
        $this->assertMatchesRegularExpression('/cmprovision-backup-.+\.tar/', $response->headers->get('Content-Disposition'));
        $names = $this->listing($response->baseResponse->getFile()->getPathname());
        $this->assertCount(3, $names, 'the folder and its two files');
        Log::shouldHaveReceived('notice')->once()->withArgs(function ($message, $context) use ($user) {
            return $message === 'Backup of the data downloaded' && $context['by'] === $user->email && $context['bytes'] > 0;
        });
    }

    public function test_a_failure_goes_back_to_the_backup_card_with_the_reason()
    {
        $this->app->instance(DataBackup::class, new DataBackup($this->app_, ':memory:', $this->root.'/work'));
        $user = User::factory()->create();

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->get('/settings/backup')
            ->assertRedirect(route('settings', ['tab' => 'status']).'#backup')
            ->assertSessionHas('backupError', 'The database of this server is not a file that can be copied.');
    }

    public function test_the_status_tab_has_the_card_with_the_link_and_the_way_back()
    {
        $this->app->instance(ServiceState::class, new FakeServiceState(['cmprovision-queue']));
        $this->actingAs(User::factory()->create());

        $this->get('/settings?tab=status')->assertOk()
            ->assertSee('Backup')
            ->assertSeeHtml('href="'.route('settings.backup').'"')
            ->assertSee('Download backup')
            ->assertSee('cmprovision-restore --data-only')
            ->assertSee('the password is asked before the download');
    }
}
