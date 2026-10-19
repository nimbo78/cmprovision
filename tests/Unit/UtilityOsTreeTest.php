<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** The buildroot tree of our utility OS (utility-os/, 2.7.0): the pins, the configuration and the scripts that run before
    the build. tools/utility-os/test.sh checks the build itself. */
class UtilityOsTreeTest extends TestCase
{
    protected static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    protected function file(string $path): string
    {
        return file_get_contents(self::root().'/'.$path);
    }

    /** versions.env as key => value */
    protected function versions(): array
    {
        preg_match_all('/^(UOS_[A-Z0-9_]+)=(.*)$/m', $this->file('utility-os/versions.env'), $m);
        return array_combine($m[1], $m[2]);
    }

    public function test_versions_env_pins_every_source_in_a_form_sh_and_make_both_read()
    {
        $v = $this->versions();
        $this->assertMatchesRegularExpression('/^\d{4}\.\d{2}(\.\d+)?$/', $v['UOS_VERSION']);
        $this->assertMatchesRegularExpression('/^2025\.02(\.\d+)?$/', $v['UOS_BUILDROOT_VERSION'], 'the LTS branch the spec chose');
        $this->assertMatchesRegularExpression('/^stable_\d{8}$/', $v['UOS_LINUX_TAG']);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $v['UOS_LINUX_VERSION']);
        $this->assertMatchesRegularExpression('/^1\.\d{8}$/', $v['UOS_FIRMWARE_TAG']);
        $this->assertMatchesRegularExpression('/^\d{8}-\d{6}$/', $v['UOS_USBBOOT_TAG']);
        foreach (['UOS_LINUX_COMMIT', 'UOS_RPI_UTILS_COMMIT'] as $key)
            $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $v[$key], $key);
        foreach (['UOS_BUILDROOT_SHA256', 'UOS_LINUX_SHA256', 'UOS_START4_SHA256', 'UOS_FIXUP4_SHA256', 'UOS_FIRMWARE_LICENCE_SHA256',
                  'UOS_RPI_UTILS_SHA256', 'UOS_BOOTFILES_SHA256', 'UOS_USBBOOT_LICENSE_SHA256'] as $key)
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $v[$key], $key);
        // make includes the file (external.mk): a quote or a space would end up in the value
        $this->assertDoesNotMatchRegularExpression('/^[^#].*["\' ]/m', $this->file('utility-os/versions.env'));
    }

    public function test_the_external_tree_reads_the_pins_before_its_packages()
    {
        $this->assertStringContainsString("name: CMPROVISION\n", $this->file('utility-os/external.desc'));
        $mk = $this->file('utility-os/external.mk');
        $this->assertLessThan(strpos($mk, 'package/*/*.mk'), strpos($mk, 'include $(BR2_EXTERNAL_CMPROVISION_PATH)/versions.env'));
        $this->assertStringContainsString('LINUX_POST_EXTRACT_HOOKS += CMPROVISION_LINUX_GPL_TEXT', $mk);
        foreach (['cmp-rpi-boot-files', 'cmp-rpi-utils', 'cmp-usbboot-bootfiles'] as $pkg)
            $this->assertStringContainsString("source \"\$BR2_EXTERNAL_CMPROVISION_PATH/package/$pkg/Config.in\"", $this->file('utility-os/Config.in'));
    }

    public function test_the_defconfig_is_the_os_the_spec_describes()
    {
        $defconfig = $this->file('utility-os/configs/cmprovision_defconfig');
        foreach (['BR2_aarch64=y', 'BR2_cortex_a72=y', 'BR2_TOOLCHAIN_EXTERNAL_BOOTLIN_AARCH64_GLIBC_STABLE=y',
                  'BR2_DOWNLOAD_FORCE_CHECK_HASHES=y', 'BR2_LINUX_KERNEL_CUSTOM_TARBALL=y', 'BR2_LINUX_KERNEL_DEFCONFIG="bcm2711"',
                  'BR2_LINUX_KERNEL_CONFIG_FRAGMENT_FILES="$(BR2_EXTERNAL_CMPROVISION_PATH)/board/cmprovision/linux.fragment"',
                  'BR2_LINUX_KERNEL_IMAGEGZ=y', 'BR2_LINUX_KERNEL_INTREE_DTS_NAME="broadcom/bcm2711-rpi-cm4 broadcom/bcm2711-rpi-4-b broadcom/bcm2711-rpi-400"',
                  'BR2_LINUX_KERNEL_INTREE_DTSO_NAMES="overlays/spi-gpio40-45 overlays/dwc2"',
                  'BR2_LINUX_KERNEL_LICENSE_FILES="COPYING LICENSES/preferred/GPL-2.0.cmprovision LICENSES/exceptions/Linux-syscall-note"',
                  'BR2_TARGET_GENERIC_HOSTNAME="scriptexecute"', 'BR2_TARGET_GENERIC_GETTY_PORT="tty1"', 'BR2_SYSTEM_DHCP=""',
                  'BR2_PACKAGE_LIBCURL_TLS_NONE=y', 'BR2_PACKAGE_UTIL_LINUX_BINARIES=y', 'BR2_PACKAGE_E2FSPROGS_RESIZE2FS=y',
                  'BR2_PACKAGE_FLASHROM=y', 'BR2_PACKAGE_CMP_RPI_UTILS=y', 'BR2_PACKAGE_CMP_RPI_BOOT_FILES=y',
                  'BR2_PACKAGE_CMP_USBBOOT_BOOTFILES=y', 'BR2_TARGET_ROOTFS_CPIO_XZ=y', '# BR2_TARGET_ROOTFS_TAR is not set'] as $line)
            $this->assertStringContainsString($line."\n", $defconfig, $line);
        $this->assertStringNotContainsString('BR2_TARGET_GENERIC_ROOT_PASSWD="', $defconfig, 'root on tty2 without a password, as the spec says');
    }

    public function test_the_kernel_fragment_adds_what_bcm2711_defconfig_lacks_and_keeps_modules()
    {
        $fragment = $this->file('utility-os/board/cmprovision/linux.fragment');
        foreach (['CONFIG_LOCALVERSION="-cmprovision"', 'CONFIG_SPI_BCM2835=y', 'CONFIG_SPI_SPIDEV=y', 'CONFIG_RASPBERRYPI_GPIOMEM=y',
                  'CONFIG_VIRTIO_MMIO=y', 'CONFIG_VIRTIO_NET=y'] as $line)
            $this->assertStringContainsString($line."\n", $fragment, $line);
        $this->assertDoesNotMatchRegularExpression('/^(# )?CONFIG_MODULES[ =]/m', $fragment, 'without modules every =m of the defconfig would be built in');
    }

    public function test_busybox_has_every_legacy_applet_and_no_network_login()
    {
        $fragment = $this->file('utility-os/board/cmprovision/busybox.fragment');
        $this->assertStringContainsString("CONFIG_TC=y\n", $fragment);
        $this->assertStringContainsString("# CONFIG_TELNETD is not set\n", $fragment);
        $this->assertStringContainsString('rm -f "$TARGET/etc/init.d/S50crond"', $this->file('utility-os/board/cmprovision/post-build.sh'));
        $this->assertStringContainsString('rm -rf "$TARGET/lib/modules"', $this->file('utility-os/board/cmprovision/post-build.sh'));
    }

    public function test_the_legacy_command_list_is_made_from_the_legacy_image()
    {
        $python = trim((string) shell_exec('command -v python3 2>/dev/null'));
        if ($python === '')
            $this->markTestSkipped('needs python3');
        exec(escapeshellarg($python).' -I '.escapeshellarg(self::root().'/tools/utility-os/legacy_commands.py').' '
            .escapeshellarg(self::root().'/scriptexecute/scriptexecute.img'), $out, $code);
        $this->assertSame(0, $code);
        $this->assertSame(implode("\n", $out)."\n", $this->file('utility-os/legacy-commands.txt'), 'run legacy_commands.py again');
        $commands = array_values(array_filter($out, fn ($l) => $l !== '' && $l[0] !== '#'));
        $this->assertCount(340, $commands);
        foreach (['busybox', 'flashrom', 'resize2fs', 'vcgencmd', 'curl', 'parted', 'mmc', 'bsdtar', '[['] as $cmd)
            $this->assertContains($cmd, $commands);
        foreach (['raspistill', 'tvservice', 'dtoverlay-pre', 'get_module', 'e2scrub'] as $cmd)
            $this->assertNotContains($cmd, $commands);
    }

    public function test_hashes_sh_writes_every_hash_file_buildroot_checks()
    {
        $dir = sys_get_temp_dir().'/uos-hashes-'.bin2hex(random_bytes(4));
        try
        {
            exec('sh '.escapeshellarg(self::root().'/tools/utility-os/hashes.sh').' '.escapeshellarg(self::root().'/utility-os/versions.env')
                .' '.escapeshellarg($dir).' 2>&1', $out, $code);
            $this->assertSame(0, $code, implode("\n", $out));
            $v = $this->versions();
            $this->assertSame("sha256  {$v['UOS_LINUX_SHA256']}  linux-{$v['UOS_LINUX_COMMIT']}.tar.gz\n", file_get_contents("$dir/linux/linux.hash"));
            $this->assertSame("sha256  {$v['UOS_START4_SHA256']}  start4.elf\nsha256  {$v['UOS_FIXUP4_SHA256']}  fixup4.dat\n"
                ."sha256  {$v['UOS_FIRMWARE_LICENCE_SHA256']}  LICENCE.broadcom\n", file_get_contents("$dir/cmp-rpi-boot-files/cmp-rpi-boot-files.hash"));
            $this->assertSame("sha256  {$v['UOS_RPI_UTILS_SHA256']}  cmp-rpi-utils-{$v['UOS_RPI_UTILS_COMMIT']}.tar.gz\n",
                file_get_contents("$dir/cmp-rpi-utils/cmp-rpi-utils.hash"));
            $this->assertSame("sha256  {$v['UOS_BOOTFILES_SHA256']}  bootfiles.bin\nsha256  {$v['UOS_USBBOOT_LICENSE_SHA256']}  LICENSE\n",
                file_get_contents("$dir/cmp-usbboot-bootfiles/cmp-usbboot-bootfiles.hash"));

            // a source without its hash would go unchecked: hashes.sh refuses
            file_put_contents("$dir.env", str_replace("UOS_START4_SHA256={$v['UOS_START4_SHA256']}", 'UOS_START4_SHA256=', $this->file('utility-os/versions.env')));
            exec('sh '.escapeshellarg(self::root().'/tools/utility-os/hashes.sh').' '.escapeshellarg("$dir.env").' '.escapeshellarg("$dir-2").' 2>&1', $out2, $code2);
            $this->assertSame(1, $code2);
            $this->assertStringContainsString('hashes.sh: UOS_START4_SHA256 has no SHA256 in versions.env', implode("\n", $out2));
        }
        finally
        {
            exec('rm -rf '.escapeshellarg($dir).' '.escapeshellarg("$dir-2").' '.escapeshellarg("$dir.env"));
        }
    }

    public function test_post_image_lays_out_the_tftp_directory()
    {
        $images = sys_get_temp_dir().'/uos-images-'.bin2hex(random_bytes(4));
        $build = $images.'-build';
        try
        {
            mkdir("$images/rpi-boot", 0755, true);
            mkdir("$build/linux-custom/arch/arm64/boot/dts/overlays", 0755, true);
            foreach (['Image.gz', 'rootfs.cpio.xz', 'bcm2711-rpi-cm4.dtb', 'bcm2711-rpi-4-b.dtb', 'bcm2711-rpi-400.dtb', 'spi-gpio40-45.dtbo',
                      'bootfiles.bin', 'rpi-boot/start4.elf', 'rpi-boot/fixup4.dat'] as $f)
                file_put_contents("$images/$f", "content of $f");
            // dwc2.dtbo only in the kernel's build directory: the fallback
            file_put_contents("$build/linux-custom/arch/arm64/boot/dts/overlays/dwc2.dtbo", 'content of dwc2.dtbo');
            exec('BR2_EXTERNAL_CMPROVISION_PATH='.escapeshellarg(self::root().'/utility-os').' BUILD_DIR='.escapeshellarg($build)
                .' sh '.escapeshellarg(self::root().'/utility-os/board/cmprovision/post-image.sh').' '.escapeshellarg($images).' 2>&1', $out, $code);
            $this->assertSame(0, $code, implode("\n", $out));

            $out64 = "$images/scriptexecute64";
            $names = [];
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($out64, \FilesystemIterator::SKIP_DOTS)) as $f)
                $names[] = substr($f->getPathname(), strlen($out64) + 1);
            sort($names);
            $this->assertSame(['MANIFEST', 'bcm2711-rpi-4-b.dtb', 'bcm2711-rpi-400.dtb', 'bcm2711-rpi-cm4.dtb', 'bootfiles.bin', 'cmdline.txt',
                               'config.txt', 'fixup4.dat', 'kernel8.img', 'overlays/dwc2.dtbo', 'overlays/spi-gpio40-45.dtbo',
                               'scriptexecute.img', 'start4.elf'], $names);
            $v = $this->versions();
            $this->assertSame('content of Image.gz', file_get_contents("$out64/kernel8.img"));
            $this->assertSame('content of rootfs.cpio.xz', file_get_contents("$out64/scriptexecute.img"));
            $this->assertStringEndsWith("&uos={$v['UOS_VERSION']}\n", file_get_contents("$out64/cmdline.txt"));
            $manifest = file_get_contents("$out64/MANIFEST");
            foreach (["version={$v['UOS_VERSION']}\n", "bits=64\n", "kernel=Linux {$v['UOS_LINUX_VERSION']} (raspberrypi/linux {$v['UOS_LINUX_TAG']})\n",
                      "usbboot={$v['UOS_USBBOOT_TAG']}\n", ' overlays/dwc2.dtbo'] as $needle)
                $this->assertStringContainsString($needle, $manifest);
        }
        finally
        {
            exec('rm -rf '.escapeshellarg($images).' '.escapeshellarg($build));
        }
    }
}
