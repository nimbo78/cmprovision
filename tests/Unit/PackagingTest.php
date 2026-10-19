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

    public function test_an_upgrade_or_a_restore_never_keeps_a_cached_route_table()
    {
        // a route cache an operator once made (artisan optimize) would keep /scriptexecute in the web group: every module
        // report a silent 419, and no stand check at all (2.5.1)
        $this->assertStringContainsString('$ARTISAN route:clear', $this->file('debian/postinst'));
        $this->assertStringContainsString('runuser -u www-data -- "$APP/artisan" route:clear', $this->file('tools/restore.sh'));
    }

    public function test_no_trace_of_the_cm3_usb_network()
    {
        // CM3 reached the server only over USB with IPv6 link-local addresses, which /scriptexecute no longer answers (2.5.1)
        $this->assertStringNotContainsString('dhcpcd', $this->file('debian/postinst'));
        $this->assertStringNotContainsString('[pi3]', $this->file('scriptexecute/config.txt'));
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/scriptexecute/cmdline.txt.ipv6ll');
    }

    public function test_the_cold_spare_is_installed_as_networkmanager_and_systemd_want_it()
    {
        // NetworkManager runs only dispatcher scripts that root owns and nobody else can change
        $rules = $this->file('debian/rules');
        $this->assertStringContainsString('install -D -m 755 tools/uplink-spare debian/cmprovision4/usr/lib/cmprovision/uplink-spare', $rules);
        $this->assertStringContainsString('install -D -m 755 tools/90-cmprovision-uplink debian/cmprovision4/usr/lib/NetworkManager/dispatcher.d/90-cmprovision-uplink', $rules);
        $install = $this->file('debian/install');
        $this->assertStringContainsString('debian/cmprovision-uplink.service lib/systemd/system', $install);
        $this->assertStringContainsString('debian/cmprovision-uplink.timer lib/systemd/system', $install);
        $timer = $this->file('debian/cmprovision-uplink.timer');
        foreach (['OnBootSec=2min', 'OnUnitActiveSec=5min', 'WantedBy=timers.target'] as $line)
            $this->assertStringContainsString($line, $timer);
        $service = $this->file('debian/cmprovision-uplink.service');
        $this->assertStringContainsString('Type=oneshot', $service);
        $this->assertStringContainsString('ExecStart=/usr/lib/cmprovision/uplink-spare check timer', $service);
        $this->assertStringNotContainsString('[Install]', $service, 'only the timer starts it');
    }

    public function test_the_cold_spare_switch_is_written_once_and_never_fails_the_configure()
    {
        $postinst = $this->file('debian/postinst');
        $this->assertStringContainsString('if [ ! -e /etc/default/cmprovision-uplink ]; then', $postinst);
        // under set -e a failed write would stop the configure, and with it the start of every service
        $this->assertStringContainsString("cat > /etc/default/cmprovision-uplink <<'EOF' || echo", $postinst);
        $this->assertStringContainsString('SPARE=yes', $postinst);
        $this->assertStringContainsString('/usr/lib/cmprovision/uplink-spare check postinst || true', $postinst);
        $this->assertLessThan(strpos($postinst, '#DEBHELPER#'), strpos($postinst, 'uplink-spare check postinst'));
        $this->assertStringContainsString('/etc/default/cmprovision-uplink', $this->file('tools/backup.sh'));
    }

    public function test_leaving_the_package_or_going_below_2_5_2_gives_the_built_in_wifi_back()
    {
        // otherwise the built-in Wi-Fi would stay disconnected until a reboot; a downgrade also takes the timer's unit away
        $prerm = $this->file('debian/prerm');
        $this->assertStringContainsString('dpkg --compare-versions "$2" lt "2.5.2~"', $prerm);
        $this->assertStringContainsString('deb-systemd-invoke stop cmprovision-uplink.timer', $prerm);
        $this->assertStringContainsString('/usr/lib/cmprovision/uplink-spare release || true', $prerm);
        $this->assertLessThan(strpos($prerm, '#DEBHELPER#'), strpos($prerm, 'uplink-spare release'));
        $this->assertStringContainsString('rm -f /etc/default/cmprovision-uplink', $this->file('debian/postrm'));
    }

    public function test_going_below_2_5_2_forgets_the_timer_so_coming_back_enables_it()
    {
        // deb-systemd-helper disable keeps a record that it once made the timer's link, so a later enable (debhelper's
        // on the next upgrade) takes the missing link for the admin's choice and leaves the 5-minute check off: purge
        // drops the link and the record, and the next upgrade enables the timer as on a first install (seen on the bench)
        $prerm = $this->file('debian/prerm');
        $this->assertStringContainsString('deb-systemd-helper purge cmprovision-uplink.timer', $prerm);
        $this->assertStringNotContainsString('deb-systemd-helper disable cmprovision-uplink.timer', $prerm);
        $this->assertStringNotContainsString('deb-systemd-helper enable cmprovision-uplink.timer', $this->file('debian/postinst'));
    }

    public function test_the_wifi_helper_is_installed_and_sudo_allows_exactly_its_commands()
    {
        $this->assertStringContainsString("\tinstall -D -m 755 tools/wifi-networks debian/cmprovision4/usr/lib/cmprovision/wifi-networks\n",
            $this->file('debian/rules'));
        $sudoers = $this->file('debian/010_cmprovision');
        foreach (['save', 'delete', 'order', 'check', 'scan'] as $command)
            $this->assertStringContainsString("www-data ALL=(root) NOPASSWD: /usr/lib/cmprovision/wifi-networks $command\n", $sudoers);
        // the command alone: the input comes on stdin, and a wildcard would let anything through
        $this->assertStringNotContainsString('*', $sudoers);
        $this->assertSame(6, substr_count($sudoers, 'NOPASSWD'), 'the dnsmasq restart and the five Wi-Fi commands');
        $this->assertStringEndsWith("\n", $sudoers);
    }

    public function test_networkmanager_gets_the_metrics_by_device()
    {
        $conf = $this->file('tools/90-cmprovision-uplink.conf');
        $this->assertStringContainsString("[connection-cmprovision-builtin-wifi]\nmatch-device=driver:brcmfmac\nipv4.route-metric=600\nipv6.route-metric=600\n", $conf);
        $this->assertStringContainsString("[connection-cmprovision-wifi]\nmatch-device=type:wifi\nipv4.route-metric=100\nipv6.route-metric=100\n", $conf);
        $this->assertLessThan(strpos($conf, '[connection-cmprovision-wifi]'), strpos($conf, '[connection-cmprovision-builtin-wifi]'),
            'the first section that matches a device wins: the built-in Wi-Fi before any Wi-Fi');
        $this->assertStringContainsString("\tinstall -D -m 644 tools/90-cmprovision-uplink.conf debian/cmprovision4/usr/lib/NetworkManager/conf.d/90-cmprovision-uplink.conf\n",
            $this->file('debian/rules'));
        $postinst = $this->file('debian/postinst');
        $this->assertStringContainsString('nmcli general reload conf >/dev/null 2>&1 || true', $postinst);
        $this->assertLessThan(strpos($postinst, '#DEBHELPER#'), strpos($postinst, 'nmcli general reload conf'));
    }

    public function test_the_led_helper_knows_the_ready_pattern()
    {
        // the OS check shows its pass with it (2.6.0); the static binary keeps its usage text
        $this->assertStringContainsString("case 'r':", $this->file('tools/ledpattern/ledpattern.c'));
        $this->assertStringContainsString('usage: ledpattern done|ready|fail|identify|check', $this->file('public/tools/ledpattern'));
    }

    public function test_the_utility_os_link_is_set_up_as_the_web_user_before_dnsmasq_starts()
    {
        // etc/ is the web user's directory: root reading and writing there would follow a symlink it planted
        $postinst = $this->file('debian/postinst');
        $this->assertStringContainsString('runuser -u www-data -- /usr/lib/cmprovision/boot-link fix /var/lib/cmprovision ||', $postinst);
        $this->assertLessThan(strpos($postinst, 'boot-link fix'), strpos($postinst, 'fix_ownership ||'), 'etc/ is the web user\'s first');
        $this->assertLessThan(strpos($postinst, 'deb-systemd-invoke restart cmprovision-dnsmasq'), strpos($postinst, 'boot-link fix'),
            'an upgrade restarts dnsmasq with the new tftp-root');
        $this->assertLessThan(strpos($postinst, '#DEBHELPER#'), strpos($postinst, 'boot-link fix'));
        $this->assertStringContainsString("\tinstall -D -m 755 tools/boot-link debian/cmprovision4/usr/lib/cmprovision/boot-link\n",
            $this->file('debian/rules'));
    }

    public function test_dnsmasq_and_rpiboot_follow_the_link_and_the_shipped_conffile_stays()
    {
        // the application edits dnsmasq.conf (dhcp-host=): a changed shipped copy would stop an upgrade at dpkg's question,
        // so postinst moves tftp-root in the live file instead
        $this->assertStringContainsString("tftp-root=/var/lib/cmprovision/scriptexecute\n", $this->file('etc/dnsmasq.conf'));
        $rpiboot = $this->file('debian/cmprovision-rpiboot.service');
        $this->assertStringContainsString("ExecStart=/usr/bin/rpiboot -l -d /var/lib/cmprovision/etc/boot\n", $rpiboot);
        // rpiboot exits on a file it cannot serve (since 20261002 a CM4 asks for one the old directory lacked)
        $this->assertStringContainsString("Restart=on-failure\nRestartSec=2\n", $rpiboot);
    }

    public function test_going_below_2_7_points_the_link_at_the_legacy_os_and_purge_removes_it()
    {
        // the older package has no scriptexecute64/, and tftp-root stays on the link
        $prerm = $this->file('debian/prerm');
        $this->assertStringContainsString('dpkg --compare-versions "$2" lt "2.7.0~"', $prerm);
        $this->assertStringContainsString('runuser -u www-data -- /usr/lib/cmprovision/boot-link legacy /var/lib/cmprovision || true', $prerm);
        $this->assertLessThan(strpos($prerm, '#DEBHELPER#'), strpos($prerm, 'boot-link legacy'));
        $this->assertStringContainsString('rm -f /var/lib/cmprovision/etc/boot', $this->file('debian/postrm'));
    }

    public function test_restore_sets_the_link_up_like_postinst()
    {
        // a snapshot of 2.6 or older names scriptexecute/ in tftp-root, and --data-only puts no link back; a snapshot's older
        // package (restore reinstalls it first) has no boot-link, and its own tftp-root is right for it
        $restore = $this->file('tools/restore.sh');
        $this->assertStringContainsString("if [ -x /usr/lib/cmprovision/boot-link ]; then\n    runuser -u www-data -- /usr/lib/cmprovision/boot-link fix \"\$APP\"", $restore);
        $this->assertLessThan(strpos($restore, 'boot-link fix'), strpos($restore, 'chown -R -h www-data:www-data'), 'after the owners are set');
        $this->assertLessThan(strpos($restore, "systemctl restart 'php*-fpm' nginx cmprovision-dnsmasq"), strpos($restore, 'boot-link fix'),
            'before dnsmasq starts again');
    }

}
