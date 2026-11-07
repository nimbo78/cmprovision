<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ConfigurationTransferBackupTest extends TestCase
{
    private string $directory;

    protected function tearDown(): void
    {
        if (isset($this->directory)) {
            exec('rm -rf -- '.escapeshellarg($this->directory));
        }
        parent::tearDown();
    }

    public function test_full_snapshot_keeps_uploaded_assets_and_omits_private_transfer_scratch(): void
    {
        if (DIRECTORY_SEPARATOR === '\\' || trim((string) shell_exec('command -v tar 2>/dev/null')) === '') {
            $this->markTestSkipped('needs a POSIX shell and tar');
        }
        $this->directory = sys_get_temp_dir().'/cmprov-transfer-backup-'.bin2hex(random_bytes(6));
        $app = $this->directory.'/app';
        foreach (['public/uploads', 'storage/app', 'storage/framework/cache', 'storage/framework/configuration-transfer/operation', 'database'] as $child) {
            mkdir($app.'/'.$child, 0700, true);
        }
        foreach (['.env', 'public/uploads/image.img', 'storage/app/firmware', 'storage/framework/cache/state', 'storage/framework/configuration-transfer/operation/private.zip', 'database/database.sqlite'] as $child) {
            file_put_contents($app.'/'.$child, 'fixture');
        }
        mkdir($this->directory.'/snapshot');
        $source = file_get_contents(dirname(__DIR__, 2).'/tools/backup.sh');
        $start = strpos($source, 'EXCLUDE=');
        $end = strpos($source, '# Serialize the network part', $start);
        // Run the actual application-archive block without root/database/system-configuration work.
        $script = 'APP='.escapeshellarg($app)."\nOUT=".escapeshellarg($this->directory.'/snapshot')."\nWITH_UPLOADS=1\n".substr($source, $start, $end - $start);
        file_put_contents($this->directory.'/archive.sh', $script);
        exec('sh '.escapeshellarg($this->directory.'/archive.sh').' 2>&1', $output, $status);
        $this->assertSame(0, $status, implode("\n", $output));
        $listing = shell_exec('tar tzf '.escapeshellarg($this->directory.'/snapshot/app.tar.gz'));

        $this->assertStringContainsString('/.env', $listing);
        $this->assertStringContainsString('/public/uploads/image.img', $listing);
        $this->assertStringContainsString('/storage/app/firmware', $listing);
        $this->assertStringContainsString('/storage/framework/cache/state', $listing);
        $this->assertStringNotContainsString('/configuration-transfer', $listing);
        $this->assertStringNotContainsString('/database/database.sqlite', $listing);
    }
}
