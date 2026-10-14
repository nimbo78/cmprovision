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

    public function test_restore_runs_artisan_as_the_web_user()
    {
        $restore = $this->file('tools/restore.sh');
        $this->assertStringContainsString('runuser -u www-data -- "$APP/artisan" migrate --force', $restore);
        $this->assertDoesNotMatchRegularExpression('#^\s*"\$APP/artisan"#m', $restore);
    }

    public function test_going_back_below_2_5_stops_the_image_worker()
    {
        // the unit file leaves with the package; without this the worker runs the old code until a reboot
        $prerm = $this->file('debian/prerm');
        $this->assertStringContainsString('dpkg --compare-versions "$2" lt "2.5.0~"', $prerm);
        $this->assertStringContainsString('deb-systemd-invoke stop cmprovision-queue-images.service', $prerm);
        $this->assertStringContainsString('#DEBHELPER#', $prerm);
    }

    public function test_the_pool_file_says_it_is_rewritten_and_the_site_moves_only_to_a_pool_that_exists()
    {
        $postinst = $this->file('debian/postinst');
        $this->assertStringContainsString('; written by the cmprovision4 package on every configure: local changes are lost', $postinst);
        $this->assertStringContainsString('if [ -f "$POOL" ] && grep -q "fastcgi_pass unix:/run/php/php[0-9.]*-fpm.sock"', $postinst);
    }

    public function test_restore_stops_the_web_server_before_it_replaces_the_database()
    {
        // in WAL every connection opens -wal and -shm by name: one left open across the swap would mix the old files
        // into the restored database; the cache holds queue locks of jobs the restored table no longer has
        $restore = $this->file('tools/restore.sh');
        $stop = strpos($restore, "systemctl stop nginx 'php*-fpm' cmprovision-queue cmprovision-queue-images cmprovision-dnsmasq");
        $this->assertNotFalse($stop);
        $this->assertLessThan(strpos($restore, 'install -o www-data -g www-data -m 600 "$SRC/database.sqlite"'), $stop);
        $this->assertStringContainsString('runuser -u www-data -- "$APP/artisan" cache:clear', $restore);
    }

    public function test_the_backup_never_opens_a_file_of_the_web_user_as_root()
    {
        // a root sqlite3 would open the -wal and -shm files in the web user's directory, where a planted symlink makes
        // root write to or chown any file; root only writes its own snapshot from what the web user's cat reads
        $backup = $this->file('tools/backup.sh');
        $this->assertStringContainsString("runuser -u www-data -- sqlite3 -cmd '.timeout 10000' \"\$APP/database/database.sqlite\" \".backup '\$TMPDB/database.sqlite'\"", $backup);
        $this->assertStringContainsString('runuser -u www-data -- cat "$TMPDB/database.sqlite" > "$OUT/database.sqlite"', $backup);
        $this->assertDoesNotMatchRegularExpression('#^\s*(sqlite3|mv|chown) #m', $backup);
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

    public function test_no_trace_of_the_cm3_usb_network()
    {
        // CM3 reached the server only over USB with IPv6 link-local addresses, which /scriptexecute no longer answers (2.5.1)
        $this->assertStringNotContainsString('dhcpcd', $this->file('debian/postinst'));
        $this->assertStringNotContainsString('[pi3]', $this->file('scriptexecute/config.txt'));
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/scriptexecute/cmdline.txt.ipv6ll');
    }
}
