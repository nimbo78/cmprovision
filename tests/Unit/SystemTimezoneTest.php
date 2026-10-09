<?php

namespace Tests\Unit;

use App\Support\SystemTimezone;
use PHPUnit\Framework\TestCase;

/**
 * The display time zone comes from the operating system. PHP cannot be asked: Debian 11's PHP
 * guesses the system zone, Debian 13's PHP 8.4 answers UTC whatever the system is set to.
 */
class SystemTimezoneTest extends TestCase
{
    private $etc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->etc = sys_get_temp_dir().'/tz-test-'.uniqid();
        mkdir($this->etc);
    }

    protected function tearDown(): void
    {
        foreach (['timezone', 'localtime'] as $f) {
            if (is_link($this->etc.'/'.$f) || is_file($this->etc.'/'.$f)) {
                unlink($this->etc.'/'.$f);
            }
        }
        if (is_dir($this->etc)) {
            rmdir($this->etc);
        }
        parent::tearDown();
    }

    private function link($target)
    {
        if (!@symlink($target, $this->etc.'/localtime')) {
            $this->markTestSkipped('symlinks are not available here');
        }
    }

    public function test_reads_etc_timezone()
    {
        file_put_contents($this->etc.'/timezone', "Europe/Moscow\n");

        $this->assertSame('Europe/Moscow', SystemTimezone::detect(null, $this->etc));
    }

    public function test_follows_the_localtime_link_when_etc_timezone_is_missing()
    {
        $this->link('/usr/share/zoneinfo/Asia/Yekaterinburg');

        $this->assertSame('Asia/Yekaterinburg', SystemTimezone::detect(null, $this->etc));
    }

    public function test_understands_a_relative_localtime_link()
    {
        $this->link('../usr/share/zoneinfo/Europe/Berlin');

        $this->assertSame('Europe/Berlin', SystemTimezone::detect(null, $this->etc));
    }

    public function test_an_explicit_setting_wins_over_the_system()
    {
        file_put_contents($this->etc.'/timezone', "Europe/Moscow\n");

        $this->assertSame('Asia/Tokyo', SystemTimezone::detect('Asia/Tokyo', $this->etc));
    }

    public function test_php_ini_is_not_consulted()
    {
        // PHP 8.4 reports date.timezone = "UTC" when php.ini does not set it, so its value cannot
        // tell an administrator's choice from the default.
        file_put_contents($this->etc.'/timezone', "Europe/Moscow\n");
        ini_set('date.timezone', 'America/New_York');
        try {
            $this->assertSame('Europe/Moscow', SystemTimezone::detect(null, $this->etc));
        } finally {
            ini_restore('date.timezone');
        }
    }

    public function test_invalid_values_are_skipped_and_utc_is_the_last_resort()
    {
        file_put_contents($this->etc.'/timezone', "Mars/Olympus_Mons\n");
        $this->assertSame('UTC', SystemTimezone::detect('Not/A_Zone', $this->etc));

        $this->link('/usr/share/zoneinfo/Europe/Moscow');
        $this->assertSame('Europe/Moscow', SystemTimezone::detect('Not/A_Zone', $this->etc));
    }
}
