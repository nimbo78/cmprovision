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

    /* fpms-lite was built as 0.1.0~cmprovision1 every time and apt saw no upgrade: it takes the version of cmprovision */
    public function test_fpms_lite_takes_the_version_of_cmprovision()
    {
        $build = $this->file('packaging/fpms/build.sh');
        $this->assertStringContainsString('dpkg-parsechangelog -l "$HERE/../../debian/changelog" -S Version', $build);
        $this->assertStringContainsString('build-lite-deb.sh" "$OUT" "$CMP_VERSION"', $build);
    }

    public function test_fpms_preferences_are_backed_up_and_restore_stops_the_shared_reader()
    {
        $this->assertStringContainsString('/etc/default/cmprovision-fpms.json', $this->file('tools/backup.sh'));
        $restore = $this->file('tools/restore.sh');
        $this->assertStringContainsString('systemctl stop cmprovision-wallboard cmprovision-wallboard-display cmprovision-fpms cmprovision-fpms-data cmprovision-wallboard-data', $restore);
        $stop = strpos($restore, "\nstop_readers\n");
        $this->assertNotFalse($stop);
        $this->assertLessThan(strpos($restore, 'restoring the data into'), $stop);
        $secondStop = strpos($restore, "\n    stop_readers\n", $stop + 1);
        $this->assertGreaterThan(strpos($restore, 'apt-get install -y --allow-downgrades'), $secondStop);
        $this->assertLessThan(strpos($restore, 'echo "restoring $APP"'), $secondStop);
        $this->assertStringContainsString('deb-systemd-invoke stop cmprovision-fpms.service cmprovision-fpms-data.service cmprovision-wallboard-data.service', $this->file('debian/prerm'),
            'the shared data producer runs PHP: an upgrade must not leave it on the old code');
        $this->assertStringContainsString('systemctl restart cmprovision-fpms', $restore);
        $this->assertStringContainsString('rm -f /etc/default/cmprovision-fpms.json', $this->file('debian/postrm'));
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

    public function test_vlan_helper_is_root_owned_and_sudo_allows_only_its_named_commands()
    {
        $this->assertStringContainsString("\tinstall -D -m 755 tools/vlan-networks debian/cmprovision4/usr/lib/cmprovision/vlan-networks\n",
            $this->file('debian/rules'));
        $this->assertStringContainsString("debian/013_cmprovision-vlan etc/sudoers.d\n", $this->file('debian/install'));
        $sudoers = $this->file('debian/013_cmprovision-vlan');
        foreach (['list', 'add', 'remove'] as $command)
            $this->assertStringContainsString("www-data ALL=(root) NOPASSWD: /usr/lib/cmprovision/vlan-networks $command\n", $sudoers);
        $this->assertSame(3, substr_count($sudoers, 'NOPASSWD'));
        $this->assertStringNotContainsString('*', $sudoers);
        $this->assertStringContainsString('/var/lib/cmprovision-network/vlans', $this->file('tools/backup.sh'));
        $this->assertStringContainsString('/etc/default/cmprovision-stand-interface', $this->file('tools/backup.sh'));
        $this->assertStringContainsString('flock -w 10 9', $this->file('tools/backup.sh'));
        $this->assertStringContainsString('the backup did not finish', $this->file('tools/restore.sh'));
    }

    public function test_network_guard_and_boot_recovery_are_installed_before_nginx(): void
    {
        $install = $this->file('debian/install');
        foreach (['cmprovision-network-init.service', 'cmprovision-network-recover.service'] as $unit)
            $this->assertStringContainsString("debian/$unit lib/systemd/system\n", $install);
        $init = $this->file('debian/cmprovision-network-init.service');
        $recover = $this->file('debian/cmprovision-network-recover.service');
        $this->assertStringContainsString('Before=NetworkManager.service nginx.service cmprovision-dnsmasq.service', $init);
        $this->assertStringContainsString('network-transaction init', $init);
        $this->assertStringContainsString('After=cmprovision-network-init.service NetworkManager.service', $recover);
        $this->assertStringContainsString('Before=nginx.service cmprovision-dnsmasq.service', $recover);
        $this->assertStringContainsString('network-transaction recover', $recover);
        $this->assertStringContainsString('/usr/lib/cmprovision/network-transaction recover || {', $this->file('debian/prerm'));
        $sudoers = $this->file('debian/014_cmprovision-network');
        foreach (['status', 'begin', 'confirm', 'cancel', 'profiles'] as $command)
            $this->assertStringContainsString("www-data ALL=(root) NOPASSWD: /usr/lib/cmprovision/network-transaction $command\n", $sudoers);
        $this->assertSame(5, substr_count($sudoers, 'NOPASSWD'));
        $this->assertStringNotContainsString('network-transaction recover', $sudoers);
        $this->assertStringNotContainsString('*', $sudoers);
        $this->assertStringContainsString('ExecStart=/usr/lib/cmprovision/dnsmasq-start', $this->file('debian/cmprovision-dnsmasq.service'));
        $this->assertStringContainsString('install -D -m 755 tools/dnsmasq-start', $this->file('debian/rules'));
    }

    public function test_the_hdmi_display_has_a_renderer_user_of_its_own_and_a_data_unit_without_a_terminal()
    {
        $display = $this->file('debian/cmprovision-wallboard-display.service');
        $data = $this->file('debian/cmprovision-wallboard-data.service');
        // the renderer draws on tty8 as a user that owns nothing else; the database reader is the web user's and has no terminal
        $this->assertStringContainsString("\nUser=cmprovision-wallboard\nGroup=cmprovision-wallboard\n", $display);
        $this->assertStringNotContainsString('www-data', $display);
        $this->assertStringContainsString("\nTTYPath=/dev/tty8\n", $display);
        $this->assertStringContainsString("\nWants=cmprovision-wallboard-data.service\n", $display);
        $this->assertStringContainsString("\nPartOf=cmprovision-wallboard.service\n", $display);
        $this->assertStringContainsString("\nStartLimitBurst=5\n", $display);
        $this->assertStringContainsString("\nUser=www-data\n", $data);
        $this->assertStringNotContainsString('TTY', $data);
        $this->assertStringNotContainsString('StandardInput', $data);
        $this->assertStringNotContainsString('PartOf=', $data, 'disconnecting HDMI must not stop data used by FPMS');
        $this->assertStringContainsString("\nStopWhenUnneeded=yes\n", $data);
        $this->assertStringContainsString('wallboard:snapshot --watch --no-ansi --output=/run/cmprovision-wallboard-data/snapshot.json', $data);
        $this->assertStringContainsString("\nRuntimeDirectory=cmprovision-wallboard-data\n", $data);
        $this->assertStringNotContainsString('[Install]', $data, 'only a display consumer starts it');
        $this->assertStringNotContainsString('[Install]', $display, 'only the watcher starts the display');
        // the renderer reads the file the data unit writes
        $this->assertStringContainsString("Path('/run/cmprovision-wallboard-data/snapshot.json')", $this->file('tools/hdmi-wallboard.py'));
    }

    public function test_the_hdmi_units_are_installed_but_only_the_watcher_is_enabled_and_started()
    {
        $install = $this->file('debian/install');
        foreach (['cmprovision-wallboard', 'cmprovision-wallboard-display', 'cmprovision-wallboard-data', 'cmprovision-fpms', 'cmprovision-fpms-data'] as $unit)
            $this->assertStringContainsString("debian/$unit.service lib/systemd/system\n", $install);
        $this->assertStringContainsString("debian/011_cmprovision-wallboard etc/sudoers.d\n", $install);
        $rules = $this->file('debian/rules');
        $this->assertStringContainsString("\tdh_installsystemd -Xcmprovision-wallboard-display.service -Xcmprovision-wallboard-data.service -Xcmprovision-fpms-data.service\n", $rules);
        $this->assertStringContainsString("\tdh_installsystemd --no-enable --no-start cmprovision-wallboard-display.service cmprovision-wallboard-data.service cmprovision-fpms-data.service\n", $rules);
        $this->assertStringContainsString("\tinstall -D -m 755 tools/hdmi-wallboard.py debian/cmprovision4/usr/lib/cmprovision/hdmi-wallboard.py\n", $rules);
        $this->assertStringContainsString("\tinstall -D -m 755 tools/wallboard-control debian/cmprovision4/usr/lib/cmprovision/wallboard-control\n", $rules);
        $watcher = $this->file('debian/cmprovision-wallboard.service');
        $this->assertStringContainsString('[Install]', $watcher);
        $this->assertStringContainsString('hdmi-wallboard.py watch', $watcher);
    }

    public function test_sudo_lets_the_web_user_run_only_the_display_settings_writer_without_arguments()
    {
        $sudoers = $this->file('debian/011_cmprovision-wallboard');
        $rules = array_values(array_filter(explode("\n", $sudoers), fn ($line) => $line !== '' && $line[0] !== '#'));
        $this->assertSame(['www-data ALL=(root) NOPASSWD: /usr/lib/cmprovision/wallboard-control ""'], $rules);
        $this->assertStringNotContainsString('*', $sudoers);
        $this->assertStringEndsWith("\n", $sudoers);
    }

    public function test_the_renderer_user_is_made_once_and_leaves_with_a_purge()
    {
        $postinst = $this->file('debian/postinst');
        $this->assertStringContainsString("if ! getent passwd cmprovision-wallboard >/dev/null; then\n", $postinst);
        $this->assertStringContainsString('adduser --system --group --no-create-home --home /nonexistent --shell /usr/sbin/nologin cmprovision-wallboard >/dev/null || true', $postinst);
        $this->assertMatchesRegularExpression('/^Depends:.*\badduser\b/m', $this->file('debian/control'));
        $postrm = $this->file('debian/postrm');
        $this->assertStringContainsString("deluser --system cmprovision-wallboard >/dev/null 2>&1 || true\n", $postrm);
        $this->assertStringContainsString('rm -f /etc/default/cmprovision-wallboard.json', $postrm);
        // the purge block, not the remove block: the settings and the user survive a plain removal
        $this->assertGreaterThan(strpos($postrm, 'if [ "$1" = "purge" ]'), strpos($postrm, 'deluser --system cmprovision-wallboard'));
    }

    public function test_every_hdmi_unit_stops_before_its_code_leaves_or_the_database_is_replaced()
    {
        $prerm = $this->file('debian/prerm');
        $this->assertStringContainsString('deb-systemd-invoke stop cmprovision-wallboard.service cmprovision-wallboard-display.service cmprovision-wallboard-data.service', $prerm);
        $this->assertStringContainsString('dpkg --compare-versions "$2" lt "2.7.5~"', $prerm);
        $this->assertStringContainsString("deb-systemd-invoke stop cmprovision-wallboard-data.service >/dev/null || true\n", $prerm);
        $restore = $this->file('tools/restore.sh');
        $this->assertStringContainsString("systemctl stop cmprovision-wallboard cmprovision-wallboard-display cmprovision-fpms cmprovision-fpms-data cmprovision-wallboard-data 2>/dev/null || true\n", $restore);
        // the stop comes before the database is swapped, the watcher comes back at the end
        $this->assertLessThan(strpos($restore, 'restoring the data into'), strpos($restore, 'cmprovision-wallboard-data'));
        $this->assertStringContainsString("systemctl restart cmprovision-wallboard 2>/dev/null || true\n", $restore);
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
        $this->assertStringContainsString("scriptexecute var/lib/cmprovision\nscriptexecute64 var/lib/cmprovision\n", $this->file('debian/install'));
        // the application edits dnsmasq.conf (dhcp-host=): a changed shipped copy would stop an upgrade at dpkg's question,
        // so postinst moves tftp-root in the live file instead
        $this->assertStringContainsString("tftp-root=/var/lib/cmprovision/scriptexecute\n", $this->file('etc/dnsmasq.conf'));
        $rpiboot = $this->file('debian/cmprovision-rpiboot.service');
        $this->assertStringContainsString("ExecStart=/usr/bin/rpiboot -l -d /var/lib/cmprovision/etc/boot/usb\n", $rpiboot);
        // rpiboot exits on a file it cannot serve (since 20261002 a CM4 asks for one the old directory lacked)
        $this->assertStringContainsString("Restart=on-failure\nRestartSec=2\n", $rpiboot);
    }

    public function test_the_dhcp_pool_starts_at_eleven_in_the_live_file_and_the_shipped_conffile_stays()
    {
        // the first ten addresses of the stand are for fixed hosts (the provisioner .1, a second station .2, ...), not leases;
        // the shipped conffile keeps its pool (a changed copy would stop an upgrade at dpkg's question), postinst moves the live one
        $postinst = $this->file('debian/postinst');
        $this->assertSame(1, preg_match("#^\s*runuser -u www-data -- sed -i '([^']+)' /var/lib/cmprovision/etc/dnsmasq\.conf \|\|#m", $postinst, $m),
            'the live file is edited as the web user: etc/ is its directory');
        $this->assertLessThan(strpos($postinst, 'deb-systemd-invoke restart cmprovision-dnsmasq'), strpos($postinst, $m[0]),
            'an upgrade restarts dnsmasq with the new pool');
        $this->assertLessThan(strpos($postinst, '#DEBHELPER#'), strpos($postinst, $m[0]));
        $shipped = $this->file('etc/dnsmasq.conf');
        $this->assertStringContainsString("dhcp-range=172.20.0.2,172.20.255.255,255.255.0.0\n", $shipped);

        $sed = function (string $text) use ($m): string {
            $file = tempnam(sys_get_temp_dir(), 'dnsmasq');
            file_put_contents($file, $text);
            exec('sed -i '.escapeshellarg($m[1]).' '.escapeshellarg($file), $out, $code);
            $this->assertSame(0, $code);
            $result = file_get_contents($file);
            unlink($file);
            return $result;
        };
        $moved = $sed($shipped);
        $this->assertStringContainsString("dhcp-range=172.20.0.11,172.20.255.255,255.255.0.0\n", $moved);
        $this->assertSame($shipped, str_replace('dhcp-range=172.20.0.11,', 'dhcp-range=172.20.0.2,', $moved), 'nothing else changes');
        $this->assertSame($moved, $sed($moved), 'the next upgrade changes nothing');
        $own = "dhcp-range=172.20.0.50,172.20.0.200,255.255.0.0\n";
        $this->assertSame($own, $sed($own), 'a pool set by hand stays');
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

    public function test_the_scripts_that_build_the_package_outside_the_provisioner_are_valid_shell_and_name_their_way()
    {
        foreach (['tools/build-deb.sh', 'tools/build-deb-wsl.sh'] as $script)
        {
            $path = dirname(__DIR__, 2).'/'.$script;
            if (trim((string) shell_exec('command -v sh 2>/dev/null')) !== '')
                $this->assertSame('', trim((string) shell_exec('sh -n '.escapeshellarg($path).' 2>&1')), $script);
            $this->assertStringContainsString('set -e', $this->file($script));
        }
        $build = $this->file('tools/build-deb.sh');
        $this->assertStringContainsString('dpkg-buildpackage -b -us -uc', $build);
        $this->assertStringContainsString('COMPOSER_BIN_COMPAT=symlink', $build, 'no .bat launchers of a build in WSL');
        $this->assertStringContainsString('tools/build-deb.sh', $this->file('tools/build-deb-wsl.sh'));
    }

    public function test_the_package_recommends_the_registry_of_mac_blocks()
    {
        // the Ports page names the maker of an unknown device from the ieee-data files, and works without them
        $this->assertMatchesRegularExpression('/^Recommends:.*\bieee-data\b/m', $this->file('debian/control'));
    }

    public function test_the_station_names_itself_over_lldp_while_the_package_is_here()
    {
        // another station knows it by that (2.23.0); without lldpd the stand works as before, so lldpd is recommended
        $this->assertMatchesRegularExpression('/^Recommends:.*\blldpd\b/m', $this->file('debian/control'));
        $this->assertStringContainsString("install -D -m 755 tools/lldp-station debian/cmprovision4/usr/lib/cmprovision/lldp-station\n",
            $this->file('debian/rules'));

        $postinst = $this->file('debian/postinst');
        $announce = "/usr/lib/cmprovision/lldp-station announce \"\$(dpkg-query -W -f='\${Version}' cmprovision4 2>/dev/null)\" || true\n";
        $this->assertStringContainsString($announce, $postinst, 'the version of this package, and never a failed configure');
        $this->assertLessThan(strpos($postinst, '#DEBHELPER#'), strpos($postinst, $announce));

        // the helper leaves with the package, so prerm withdraws; an older package would leave a description that lies
        $prerm = $this->file('debian/prerm');
        $this->assertStringContainsString("if [ \"\$1\" = \"remove\" ] || { [ \"\$1\" = \"upgrade\" ] && [ -n \"\$2\" ] && dpkg --compare-versions \"\$2\" lt \"2.23.0~\"; }; then\n"
            ."    /usr/lib/cmprovision/lldp-station withdraw || true\nfi\n", $prerm);
        $this->assertLessThan(strpos($prerm, '#DEBHELPER#'), strpos($prerm, 'lldp-station withdraw'));
    }
}
