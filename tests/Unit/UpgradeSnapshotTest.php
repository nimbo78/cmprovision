<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * debian/preinst before an upgrade (2.17.0): every upgrade takes a snapshot; below 2.5 it stops when none can be made, from 2.5 on
 * it says so and goes on. The script is run as it is, with its paths moved into a temporary directory and the old version's
 * cmprovision-backup, df and du replaced by stubs.
 */
class UpgradeSnapshotTest extends TestCase
{
    protected string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        if (DIRECTORY_SEPARATOR === '\\' || trim((string) shell_exec('command -v sh dpkg 2>/dev/null')) === '' || trim((string) shell_exec('command -v dpkg 2>/dev/null')) === '')
            $this->markTestSkipped('needs a POSIX shell and dpkg');
        $this->dir = sys_get_temp_dir().'/cmprov-preinst-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/bin', 0777, true);
        mkdir($this->dir.'/backups');
        mkdir($this->dir.'/app');
        $script = file_get_contents(dirname(__DIR__, 2).'/debian/preinst');
        $script = str_replace(
            ['/usr/sbin/cmprovision-backup', 'df -Pk /var/backups', 'du -sk --exclude=uploads /var/lib/cmprovision', '/var/backups/cmprovision', 'sleep 5'],
            [$this->dir.'/bin/cmprovision-backup', $this->dir.'/bin/df', $this->dir.'/bin/du', $this->dir.'/backups', 'sleep 0'], $script);
        file_put_contents($this->dir.'/preinst', $script);
        $this->stub('df', "echo 'Filesystem 1K-blocks Used Available Use% Mounted'\necho \"x 1000000 1 \${FAKE_FREE_KB:-900000} 1% /\"\n");
        $this->stub('du', "echo \"\${FAKE_NEED_KB:-100000}\t/var/lib/cmprovision\"\n");
    }

    protected function tearDown(): void
    {
        if (isset($this->dir) && is_dir($this->dir))
            shell_exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    protected function stub(string $name, string $body): void
    {
        file_put_contents($this->dir.'/bin/'.$name, "#!/bin/sh\n".$body);
        chmod($this->dir.'/bin/'.$name, 0755);
    }

    /* The old version's cmprovision-backup: writes <dest>/<time>-<version>/ with a copy of the package, or fails; counts its calls */
    protected function backup(bool $works, string $version): void
    {
        $this->stub('cmprovision-backup', 'echo x >> '.escapeshellarg($this->dir.'/calls')."\n".($works
            ? 'mkdir -p "$1/20261024-010000-'.$version.'" && echo db > "$1/20261024-010000-'.$version.'/database.sqlite" && echo deb > "$1/20261024-010000-'.$version.'/cmprovision4_'.$version.'_all.deb"'."\n"
            : "echo 'tar: something changed' >&2\nexit 1\n"));
    }

    protected function calls(): int
    {
        return file_exists($this->dir.'/calls') ? count(file($this->dir.'/calls')) : 0;
    }

    protected function run_(string $args, array $env = []): array
    {
        $prefix = '';
        foreach ($env as $key => $value)
            $prefix .= $key.'='.escapeshellarg($value).' ';
        $out = [];
        exec($prefix.'sh '.escapeshellarg($this->dir.'/preinst').' '.$args.' 2>&1', $out, $code);
        return [$code, implode("\n", $out)];
    }

    public function test_an_upgrade_from_2_5_or_later_takes_a_snapshot_and_keeps_it_with_the_others()
    {
        $this->backup(true, '2.14.0');
        [$code, $out] = $this->run_('upgrade 2.14.0');
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('snapshot of 2.14.0 in '.$this->dir.'/backups/20261024-010000-2.14.0', $out);
        $this->assertFileExists($this->dir.'/backups/20261024-010000-2.14.0/database.sqlite');
        $this->assertDirectoryDoesNotExist($this->dir.'/backups/.before-upgrade');
    }

    public function test_the_same_version_over_itself_is_an_upgrade_too()
    {
        $this->backup(true, '2.14.0');
        [$code] = $this->run_('upgrade 2.14.0');
        $this->assertSame(0, $code);
        $this->assertSame(1, $this->calls());
    }

    public function test_from_2_5_on_a_snapshot_that_cannot_be_made_does_not_stop_the_upgrade()
    {
        $this->backup(false, '2.14.0');
        [$code, $out] = $this->run_('upgrade 2.14.0');
        $this->assertSame(0, $code, $out);
        $this->assertSame(3, $this->calls(), 'three tries');
        $this->assertStringContainsString('no snapshot of 2.14.0 could be taken', $out);
        $this->assertStringContainsString('upgrading without one', $out);
        $this->assertSame([], glob($this->dir.'/backups/*'), 'nothing is left of the failed tries');
    }

    public function test_below_2_5_a_snapshot_that_cannot_be_made_stops_the_upgrade()
    {
        $this->backup(false, '2.4.1');
        [$code, $out] = $this->run_('upgrade 2.4.1');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('The upgrade stops and 2.4.1 stays installed', $out);
        $this->assertStringContainsString('CMPROVISION_NO_SNAPSHOT=1', $out);
    }

    public function test_the_switch_that_skips_the_snapshot_still_works_everywhere()
    {
        $this->backup(false, '2.4.1');
        foreach (['2.4.1', '2.14.0'] as $old)
        {
            [$code, $out] = $this->run_('upgrade '.$old, ['CMPROVISION_NO_SNAPSHOT' => '1']);
            $this->assertSame(0, $code, $out);
            $this->assertStringContainsString('without a snapshot', $out);
        }
        $this->assertSame(0, $this->calls());
    }

    public function test_a_server_with_no_room_goes_on_without_a_snapshot_from_2_5_on_and_is_not_asked_below()
    {
        $this->backup(true, '2.14.0');
        [$code, $out] = $this->run_('upgrade 2.14.0', ['FAKE_NEED_KB' => '500000', 'FAKE_FREE_KB' => '900000']);   // twice 500 MB needs 1 GB
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('not enough room in /var/backups', $out);
        $this->assertSame(0, $this->calls());

        $this->backup(true, '2.4.1');
        [$code, $out] = $this->run_('upgrade 2.4.1', ['FAKE_NEED_KB' => '500000', 'FAKE_FREE_KB' => '900000']);
        $this->assertSame(0, $code, $out);
        $this->assertSame(1, $this->calls(), 'below 2.5 it tries anyway: a snapshot there is a must');
    }

    public function test_a_first_install_and_a_removal_take_nothing()
    {
        $this->backup(true, '2.14.0');
        foreach (['install', 'install 2.14.0', 'abort-upgrade 2.14.0'] as $args)
        {
            [$code] = $this->run_($args);
            $this->assertSame(0, $code, $args);
        }
        $this->assertSame(0, $this->calls());
    }

    public function test_a_server_with_no_backup_script_upgrades_without_a_snapshot()
    {
        [$code, $out] = $this->run_('upgrade 2.14.0');
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('has no cmprovision-backup', $out);
    }

    public function test_postinst_trims_the_snapshots_after_an_upgrade_and_never_fails_on_it()
    {
        $postinst = file_get_contents(dirname(__DIR__, 2).'/debian/postinst');
        $this->assertMatchesRegularExpression('~\[ -x /usr/sbin/cmprovision-backup \]; then\s+/usr/sbin/cmprovision-backup --prune \|\| true\s+fi~', $postinst);
    }
}
