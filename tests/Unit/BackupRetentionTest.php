<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The backup script keeps the newest snapshots of its default destination (2.12.4). The snapshot itself needs root, the web
 * user and the live database; the trimming is tested here with `--prune` on a directory of fake snapshots.
 */
class BackupRetentionTest extends TestCase
{
    protected string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        if (DIRECTORY_SEPARATOR === '\\' || trim((string) shell_exec('command -v sh 2>/dev/null')) === '')
            $this->markTestSkipped('needs a POSIX shell');
        $this->dir = sys_get_temp_dir().'/cmprov-backup-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        if (isset($this->dir) && is_dir($this->dir))
            shell_exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    /* Ten snapshots, one a day, and things that are not snapshots */
    protected function fill(): array
    {
        $names = [];
        for ($day = 1; $day <= 10; $day++)
        {
            $name = sprintf('202610%02d-120000-2.12.%d', $day, $day);
            mkdir($this->dir.'/'.$name);
            file_put_contents($this->dir.'/'.$name.'/database.sqlite', 'x');
            $names[] = $name;
        }
        mkdir($this->dir.'/.before-upgrade');
        mkdir($this->dir.'/notes');
        file_put_contents($this->dir.'/20261001-120000-readme.txt', 'a file with a snapshot-like name');
        return $names;
    }

    protected function run_(string $args, array $env = []): array
    {
        $prefix = '';
        foreach ($env as $key => $value)
            $prefix .= $key.'='.escapeshellarg($value).' ';
        $out = [];
        exec($prefix.'sh '.escapeshellarg(dirname(__DIR__, 2).'/tools/backup.sh').' '.$args.' 2>&1', $out, $code);
        return [$code, implode("\n", $out)];
    }

    protected function left(): array
    {
        return array_values(array_diff(scandir($this->dir), ['.', '..']));
    }

    public function test_prune_keeps_the_newest_five_by_default_and_leaves_everything_else()
    {
        $names = $this->fill();
        [$code, $out] = $this->run_('--prune '.escapeshellarg($this->dir));
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('removed 5 older snapshot(s)', $out);
        $left = $this->left();
        foreach (array_slice($names, 5) as $name)
            $this->assertContains($name, $left, 'the newest stay');
        foreach (array_slice($names, 0, 5) as $name)
            $this->assertNotContains($name, $left, 'the oldest go');
        foreach (['.before-upgrade', 'notes', '20261001-120000-readme.txt'] as $other)
            $this->assertContains($other, $left, 'only snapshot directories are touched');
    }

    public function test_keep_sets_how_many_stay()
    {
        $names = $this->fill();
        [$code, $out] = $this->run_('--prune --keep 2 '.escapeshellarg($this->dir));
        $this->assertSame(0, $code, $out);
        $snapshots = array_values(array_filter($this->left(), fn ($n) => preg_match('/^\d{8}-\d{6}-2\./', $n)));
        $this->assertSame(array_slice($names, 8), $snapshots);

        $this->run_('--prune --keep=1 '.escapeshellarg($this->dir));
        $snapshots = array_values(array_filter($this->left(), fn ($n) => preg_match('/^\d{8}-\d{6}-2\./', $n)));
        $this->assertSame([$names[9]], $snapshots);
    }

    public function test_the_environment_sets_the_default_and_all_keeps_everything()
    {
        $names = $this->fill();
        $this->run_('--prune '.escapeshellarg($this->dir), ['CMPROVISION_BACKUP_KEEP' => '8']);
        $this->assertCount(8, array_filter($this->left(), fn ($n) => preg_match('/^\d{8}-\d{6}-2\./', $n)));

        [$code] = $this->run_('--prune --keep all '.escapeshellarg($this->dir));
        $this->assertSame(0, $code);
        $this->assertCount(8, array_filter($this->left(), fn ($n) => preg_match('/^\d{8}-\d{6}-2\./', $n)), 'all removes nothing');
        $this->assertContains($names[9], $this->left());
    }

    public function test_fewer_snapshots_than_the_limit_are_all_kept()
    {
        $this->fill();
        [$code, $out] = $this->run_('--prune --keep 10 '.escapeshellarg($this->dir));
        $this->assertSame(0, $code, $out);
        $this->assertStringNotContainsString('removed', $out);
        $this->assertCount(10, array_filter($this->left(), fn ($n) => preg_match('/^\d{8}-\d{6}-2\./', $n)));
    }

    public function test_a_symlink_named_like_a_snapshot_is_not_followed()
    {
        $this->fill();
        $target = $this->dir.'/precious';
        mkdir($target);
        file_put_contents($target.'/keep.txt', 'x');
        symlink($target, $this->dir.'/20260901-120000-link');
        $this->run_('--prune --keep 1 '.escapeshellarg($this->dir));
        $this->assertFileExists($target.'/keep.txt');
        $this->assertTrue(is_link($this->dir.'/20260901-120000-link'));
    }

    public function test_files_and_links_with_a_snapshot_name_do_not_take_a_place_among_the_newest()
    {
        $names = $this->fill();   // includes a file named 20261001-120000-readme.txt
        symlink($this->dir.'/notes', $this->dir.'/20261099-120000-link');
        file_put_contents($this->dir.'/20261098-120000-file', 'x');
        $this->run_('--prune --keep 3 '.escapeshellarg($this->dir));
        $snapshots = array_values(array_filter($this->left(), fn ($n) => is_dir($this->dir.'/'.$n) && !is_link($this->dir.'/'.$n) && preg_match('/^\d{8}-\d{6}-2\./', $n)));
        $this->assertSame(array_slice($names, 7), $snapshots, 'three real snapshots stay');
    }

    public function test_nonsense_limits_are_refused_before_anything_is_removed()
    {
        $this->fill();
        foreach (['--keep 0', '--keep -1', '--keep abc', '--keep 03', '--keep ""', '--keep'] as $bad)
        {
            [$code, $out] = $this->run_('--prune '.$bad.' '.escapeshellarg($this->dir));
            $this->assertNotSame(0, $code, $bad);
        }
        [$code, $out] = $this->run_('--prune --bogus '.escapeshellarg($this->dir));
        $this->assertSame(2, $code);
        $this->assertStringContainsString('unknown option', $out);
        $this->assertCount(10, array_filter($this->left(), fn ($n) => preg_match('/^\d{8}-\d{6}-2\./', $n)));
    }

    public function test_only_the_default_destination_is_trimmed_after_a_snapshot()
    {
        $script = file_get_contents(dirname(__DIR__, 2).'/tools/backup.sh');
        $this->assertMatchesRegularExpression('/if \[ "\$DEST" = "\$DEFAULT_DEST" \]; then\s+prune\s+fi/', $script);
        $this->assertStringContainsString('DEFAULT_DEST=/var/backups/cmprovision', $script);
        $this->assertStringContainsString('"$path" = "${OUT:-}"', $script, 'the snapshot just made is never removed');
    }
}
