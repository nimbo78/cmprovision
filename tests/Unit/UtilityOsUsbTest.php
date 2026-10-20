<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** USB loads one FAT ramdisk; its kernel, init and script URL must be the selected utility OS's. */
class UtilityOsUsbTest extends TestCase
{
    protected string $temporary;

    protected static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['python3', 'mkfs.fat', 'mcopy'] as $tool) {
            $probe = new Process(['sh', '-c', 'command -v "$1"', 'check', $tool]);
            $probe->run();
            if (!$probe->isSuccessful()) $this->markTestSkipped('needs Python, dosfstools and mtools');
        }
        $this->temporary = sys_get_temp_dir().'/uos-usb-'.bin2hex(random_bytes(6));
        mkdir($this->temporary);
    }

    protected function tearDown(): void
    {
        if (isset($this->temporary)) (new Process(['rm', '-rf', '--', $this->temporary]))->run();
        parent::tearDown();
    }

    public static function systems(): array
    {
        return [['scriptexecute', 'kernel.img'], ['scriptexecute64', 'kernel8.img']];
    }

    protected function files(string $kernel): array
    {
        return ['config.txt', 'cmdline.txt', $kernel, 'scriptexecute.img', 'start4.elf', 'fixup4.dat',
            'bcm2711-rpi-cm4.dtb', 'bcm2711-rpi-4-b.dtb', 'bcm2711-rpi-400.dtb',
            'overlays/dwc2.dtbo', 'overlays/spi-gpio40-45.dtbo'];
    }

    protected function extract(string $image, string $file): string
    {
        $process = new Process(['mcopy', '-i', $image, '::/'.$file, '-']);
        $process->mustRun();
        return $process->getOutput();
    }

    #[DataProvider('systems')]
    public function test_the_shipped_usb_image_contains_the_selected_os(string $directory, string $kernel)
    {
        $source = self::root().'/'.$directory;
        $this->assertSame("boot_ramdisk=1\n", file_get_contents($source.'/usb/config.txt'));
        $this->assertSame(hash_file('sha256', $source.'/bootfiles.bin'), hash_file('sha256', $source.'/usb/bootfiles.bin'));
        foreach ($this->files($kernel) as $file) {
            $this->assertSame(hash_file('sha256', $source.'/'.$file),
                hash('sha256', $this->extract($source.'/usb/boot.img', $file)), $directory.'/'.$file);
        }
        $this->assertStringNotContainsString('8765', $this->extract($source.'/usb/boot.img', 'cmdline.txt'));
        $this->assertStringContainsString('/scriptexecute?', $this->extract($source.'/usb/boot.img', 'cmdline.txt'));
    }

    #[DataProvider('systems')]
    public function test_rebuilding_uses_current_inputs_without_nesting_the_previous_image(string $directory, string $kernel)
    {
        $source = $this->temporary.'/os';
        mkdir($source.'/overlays', 0755, true);
        foreach ([...$this->files($kernel), 'bootfiles.bin'] as $file)
            file_put_contents($source.'/'.$file, 'original '.$file."\n");
        file_put_contents($source.'/MANIFEST', "version=fixture\nbits=64\n");
        $builder = self::root().'/tools/utility-os/usb_image.py';
        (new Process(['python3', $builder, $source]))->mustRun();
        file_put_contents($source.'/'.$kernel, 'changed kernel');

        (new Process(['python3', $builder, $source]))->mustRun();

        $this->assertSame('changed kernel', $this->extract($source.'/usb/boot.img', $kernel));
        $this->assertSame("original config.txt\n", file_get_contents($source.'/config.txt'), 'TFTP config stays intact');
        $nested = new Process(['mcopy', '-i', $source.'/usb/boot.img', '::/usb/boot.img', '-']);
        $nested->run();
        $this->assertFalse($nested->isSuccessful(), 'previous images must never be packed recursively');
        $manifest = file_get_contents($source.'/MANIFEST');
        $this->assertStringContainsString('file='.hash_file('sha256', $source.'/usb/boot.img').' '
            .filesize($source.'/usb/boot.img')." usb/boot.img\n", $manifest);
        $accepted = hash_file('sha256', $source.'/usb/boot.img');
        unlink($source.'/scriptexecute.img');
        $failed = new Process(['python3', $builder, $source]);
        $failed->run();
        $this->assertFalse($failed->isSuccessful(), 'incomplete OS must not replace the accepted USB image');
        $this->assertSame($accepted, hash_file('sha256', $source.'/usb/boot.img'));
    }
}
