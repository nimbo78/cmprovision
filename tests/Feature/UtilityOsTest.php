<?php

namespace Tests\Feature;

use App\Services\UtilityOs;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\UtilityOsTree;
use Tests\TestCase;

/** App\Services\UtilityOs (2.7.0): the utility OSes of the package, the one the link etc/boot gives modules, the switch */
class UtilityOsTest extends TestCase
{
    use UtilityOsTree;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeTree();
    }

    public function test_both_systems_with_what_their_manifests_say()
    {
        $systems = app(UtilityOs::class)->systems();

        $this->assertSame(['scriptexecute64', 'scriptexecute'], array_keys($systems), 'ours first');
        $this->assertSame('2026.10', $systems['scriptexecute64']['version']);
        $this->assertSame('64', $systems['scriptexecute64']['bits']);
        $this->assertSame('Linux 5.4', $systems['scriptexecute']['kernel']);
        $this->assertSame(['sha256' => hash('sha256', 'kernel of scriptexecute'), 'size' => 23],
            $systems['scriptexecute']['files']['kernel8.img']);
        $this->assertSame('2026.10 (64-bit)', UtilityOs::label($systems['scriptexecute64']));
        $this->assertSame('legacy (32-bit)', UtilityOs::label($systems['scriptexecute']));
        $this->assertSame('none', UtilityOs::label(null));
    }

    public function test_the_current_one_is_where_the_link_points()
    {
        $os = app(UtilityOs::class);
        $this->assertSame('scriptexecute', $os->current());

        unlink($this->tree.'/etc/boot');
        $this->assertNull($os->current(), 'no link');

        symlink('/etc', $this->tree.'/etc/boot');
        $this->assertNull($os->current(), 'a link elsewhere is no OS of the package');
    }

    public function test_switching_replaces_the_link_and_leaves_nothing_behind()
    {
        $os = app(UtilityOs::class);

        $os->switchTo('scriptexecute64');

        $this->assertSame('../scriptexecute64', readlink($this->tree.'/etc/boot'));
        $this->assertSame('scriptexecute64', $os->current());
        $this->assertSame([], glob($this->tree.'/etc/.boot-*'), 'the new link was renamed over the old one');
    }

    public function test_only_an_os_of_the_package_can_be_served()
    {
        $os = app(UtilityOs::class);
        foreach (['../../etc', '/etc', 'storage', ''] as $dir)
        {
            try
            {
                $os->switchTo($dir);
                $this->fail("switched to '$dir'");
            }
            catch (\InvalidArgumentException $e)
            {
                $this->assertSame('../scriptexecute', readlink($this->tree.'/etc/boot'), "'$dir' left the link alone");
            }
        }
    }

    public function test_a_directory_without_a_good_manifest_is_no_os()
    {
        // a half-copied directory must not become a choice that serves broken files
        file_put_contents($this->tree.'/scriptexecute64/MANIFEST', "# cut short\nbits=64\n");
        $os = app(UtilityOs::class);

        $this->assertSame(['scriptexecute'], array_keys($os->systems()));
        $this->expectException(\InvalidArgumentException::class);
        $os->switchTo('scriptexecute64');
    }

    public function test_tftp_root_that_follows_the_link()
    {
        $os = app(UtilityOs::class);
        $this->assertTrue($os->tftpRootFollowsLink());
        $this->assertSame($this->tree.'/etc/boot', $os->tftpRoot());

        file_put_contents($this->tree.'/etc/dnsmasq.conf', "enable-tftp\ntftp-root={$this->tree}/scriptexecute\n");
        $this->assertFalse($os->tftpRootFollowsLink());
        $this->assertSame($this->tree.'/scriptexecute', $os->tftpRoot());

        unlink($this->tree.'/etc/dnsmasq.conf');
        $this->assertNull($os->tftpRootFollowsLink(), 'unknown, not a warning');
        $this->assertNull($os->tftpRoot());
    }

    public static function brokenSystems(): array
    {
        return array_map(fn ($case) => [$case], ['missing-file', 'wrong-size', 'wrong-hash', 'empty-version',
            'duplicate-file', 'malformed-file', 'traversal', 'unlisted-file', 'outside-symlink', 'null-path']);
    }

    #[DataProvider('brokenSystems')]
    public function test_a_partial_or_damaged_os_cannot_be_selected(string $case)
    {
        $dir = $this->tree.'/scriptexecute64';
        $manifest = file_get_contents($dir.'/MANIFEST');
        $fileLine = 'file='.hash('sha256', 'kernel of scriptexecute64').' 25 kernel8.img';
        switch ($case)
        {
            case 'missing-file': unlink($dir.'/kernel8.img'); break;
            case 'wrong-size': file_put_contents($dir.'/kernel8.img', 'short'); break;
            case 'wrong-hash': file_put_contents($dir.'/kernel8.img', 'KERNEL OF SCRIPTEXECUTE64'); break;
            case 'empty-version': $manifest = str_replace('version=2026.10', 'version=', $manifest); break;
            case 'duplicate-file': $manifest .= $fileLine."\n"; break;
            case 'malformed-file': $manifest .= "file=not-a-record\n"; break;
            case 'traversal': $manifest .= 'file='.str_repeat('a', 64)." 1 ../outside\n"; break;
            case 'null-path': $manifest .= 'file='.str_repeat('a', 64)." 1 broken\0path\n"; break;
            case 'unlisted-file': file_put_contents($dir.'/unexpected', 'extra'); break;
            case 'outside-symlink':
                file_put_contents($this->tree.'/outside', 'kernel of scriptexecute64');
                unlink($dir.'/kernel8.img'); symlink('../outside', $dir.'/kernel8.img'); break;
        }
        file_put_contents($dir.'/MANIFEST', $manifest);
        $os = app(UtilityOs::class);
        $this->assertNull($os->manifest('scriptexecute64'));
        $this->assertArrayNotHasKey('scriptexecute64', $os->systems());
        $this->expectException(\InvalidArgumentException::class);
        $os->switchTo('scriptexecute64');
    }

    public function test_the_current_target_must_still_be_a_valid_os()
    {
        unlink($this->tree.'/etc/boot');
        symlink('../scriptexecute64', $this->tree.'/etc/boot');
        unlink($this->tree.'/scriptexecute64/MANIFEST');
        $this->assertNull(app(UtilityOs::class)->current());
    }
}
