<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** tools/boot-link (2.7.0): the link etc/boot that dnsmasq's tftp-root and rpiboot -d follow to the utility OS modules
    get. postinst, prerm and cmprovision-restore run it as the web user; here it runs under sh in an application
    directory of its own */
class BootLinkTest extends TestCase
{
    protected string $app;

    protected function setUp(): void
    {
        parent::setUp();
        if (trim((string) shell_exec('command -v sh 2>/dev/null')) === '')
            $this->markTestSkipped('needs sh');
        $this->app = sys_get_temp_dir().'/boot-link-'.bin2hex(random_bytes(4));
        mkdir($this->app.'/etc', 0755, true);
        mkdir($this->app.'/scriptexecute');
        mkdir($this->app.'/scriptexecute64');
        file_put_contents($this->conf(), "port=0\nenable-tftp\ntftp-root={$this->app}/scriptexecute\n\nlog-dhcp\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->app));
        parent::tearDown();
    }

    protected function conf(): string
    {
        return $this->app.'/etc/dnsmasq.conf';
    }

    protected function link(): string
    {
        return $this->app.'/etc/boot';
    }

    /** runs the helper; its exit code */
    protected function helper(string ...$args): int
    {
        exec('sh '.escapeshellarg(dirname(__DIR__, 2).'/tools/boot-link').' '.implode(' ', array_map('escapeshellarg', $args)).' 2>&1', $out, $code);
        return $code;
    }

    public function test_fix_points_a_new_link_at_the_legacy_os_and_moves_tftp_root_to_it()
    {
        $this->assertSame(0, $this->helper('fix', $this->app));

        $this->assertSame('../scriptexecute', readlink($this->link()), 'relative: it works wherever the directory is mounted');
        $this->assertSame("port=0\nenable-tftp\ntftp-root={$this->app}/etc/boot\n\nlog-dhcp\n", file_get_contents($this->conf()),
            'only that line changes');
    }

    public function test_fix_twice_changes_nothing()
    {
        $this->helper('fix', $this->app);
        $conf = file_get_contents($this->conf());

        $this->assertSame(0, $this->helper('fix', $this->app));

        $this->assertSame('../scriptexecute', readlink($this->link()));
        $this->assertSame($conf, file_get_contents($this->conf()));
    }

    public function test_fix_keeps_the_new_os_once_chosen()
    {
        symlink('../scriptexecute64', $this->link());

        $this->assertSame(0, $this->helper('fix', $this->app));

        $this->assertSame('../scriptexecute64', readlink($this->link()), 'an upgrade keeps the operator\'s choice');
    }

    public function test_fix_points_a_link_to_a_missing_os_back_at_the_legacy_one()
    {
        // a snapshot of a later version restored here, or a directory gone with a downgrade
        rmdir($this->app.'/scriptexecute64');
        symlink('../scriptexecute64', $this->link());

        $this->assertSame(0, $this->helper('fix', $this->app));

        $this->assertSame('../scriptexecute', readlink($this->link()));
    }

    public function test_fix_points_a_foreign_link_back_at_the_legacy_one()
    {
        symlink('/etc', $this->link());

        $this->assertSame(0, $this->helper('fix', $this->app));

        $this->assertSame('../scriptexecute', readlink($this->link()));
    }

    public function test_fix_leaves_a_tftp_root_set_by_hand_alone()
    {
        file_put_contents($this->conf(), "enable-tftp\ntftp-root=/srv/tftp\n");

        $this->assertSame(0, $this->helper('fix', $this->app));

        $this->assertSame("enable-tftp\ntftp-root=/srv/tftp\n", file_get_contents($this->conf()));
    }

    public function test_legacy_points_the_link_back()
    {
        symlink('../scriptexecute64', $this->link());

        $this->assertSame(0, $this->helper('legacy', $this->app));

        $this->assertSame('../scriptexecute', readlink($this->link()));
    }

    public function test_a_directory_in_the_way_is_refused()
    {
        mkdir($this->link());

        $this->assertSame(1, $this->helper('fix', $this->app));

        $this->assertTrue(is_dir($this->link()) && !is_link($this->link()));
        $this->assertFileDoesNotExist($this->link().'/scriptexecute', 'ln never puts the link inside it');
    }

    public function test_a_wrong_call_is_refused()
    {
        $this->assertSame(2, $this->helper('switch', $this->app));
        $this->assertSame(2, $this->helper('fix', $this->app.'/nowhere'));
        $this->assertSame(2, $this->helper('fix'));
        $this->assertFalse(is_link($this->link()));
    }
}
