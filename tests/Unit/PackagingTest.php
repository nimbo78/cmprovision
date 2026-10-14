<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** What the Debian package and its tools do on the server, checked in their files; the WSL bench runs them
    (tests/load/README.md) */
class PackagingTest extends TestCase
{
    protected function file(string $path): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/'.$path);
    }

    public function test_postinst_runs_artisan_as_the_web_user()
    {
        // the database is in WAL: -wal and -shm files a root process created would lock the web server out
        $postinst = $this->file('debian/postinst');
        $this->assertStringContainsString('ARTISAN="runuser -u www-data -- /var/lib/cmprovision/artisan"', $postinst);
        $this->assertDoesNotMatchRegularExpression('#^\s*/var/lib/cmprovision/artisan #m', $postinst);
        $this->assertLessThan(strpos($postinst, '$ARTISAN'), strpos($postinst, 'fix_ownership ||'),
            'the owners are right before artisan writes as the web user');
    }

    public function test_backup_and_restore_open_the_database_as_the_web_user()
    {
        $this->assertStringContainsString('runuser -u www-data -- sqlite3', $this->file('tools/backup.sh'));
        $restore = $this->file('tools/restore.sh');
        $this->assertStringContainsString('runuser -u www-data -- "$APP/artisan" migrate --force', $restore);
        $this->assertDoesNotMatchRegularExpression('#^\s*"\$APP/artisan"#m', $restore);
    }

    public function test_two_queue_workers()
    {
        $this->assertStringContainsString('queue:work --queue=boards,notifications', $this->file('debian/cmprovision-queue.service'));
        // boards first in both: a slow chat server never holds up a module's board; default: jobs left by 2.4
        $this->assertStringContainsString('queue:work --queue=boards,images,default', $this->file('debian/cmprovision-queue-images.service'));
        $this->assertStringContainsString('debian/cmprovision-queue-images.service lib/systemd/system', $this->file('debian/install'));
        $this->assertStringContainsString('cmprovision-queue-images', $this->file('tools/restore.sh'));
    }

    public function test_the_site_talks_to_a_pool_of_its_own()
    {
        // the site is a conffile that postinst edits: a changed shipped copy would stop an upgrade at dpkg's question
        $this->assertStringContainsString('fastcgi_pass unix:/run/php/php-fpm.sock;', $this->file('debian/cmprovision'));
        $postinst = $this->file('debian/postinst');
        $this->assertStringContainsString('s#fastcgi_pass unix:/run/php/php[0-9.]*-fpm.sock#fastcgi_pass unix:/run/php/cmprovision-fpm.sock#', $postinst);
        foreach (['[cmprovision]', 'listen = /run/php/cmprovision-fpm.sock', 'pm = dynamic', 'pm.max_children = 16',
                  'pm.start_servers = 4', 'pm.min_spare_servers = 2', 'pm.max_spare_servers = 6', 'pm.max_requests = 500'] as $line)
            $this->assertStringContainsString($line, $postinst);
        $this->assertStringContainsString('rm -f /etc/php/*/fpm/pool.d/cmprovision.conf', $this->file('debian/postrm'));
    }
}
