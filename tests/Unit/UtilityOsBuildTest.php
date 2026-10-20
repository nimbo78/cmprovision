<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** Exercise the build wrapper with a stand-in for Buildroot's stamp-based source cache. */
class UtilityOsBuildTest extends TestCase
{
    protected string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        if (!is_executable('/bin/sh') || !is_executable('/usr/bin/rsync'))
            $this->markTestSkipped('needs a Linux build host');
        $this->dir = sys_get_temp_dir().'/uos-build-'.bin2hex(random_bytes(6));
        foreach (['repo/tools/utility-os', 'repo/utility-os', 'bin', 'work/buildroot-2025.02.18/utils', 'cache'] as $path)
            mkdir($this->dir.'/'.$path, 0755, true);
        mkdir($this->dir.'/repo/utility-os/configs');
        $root = dirname(__DIR__, 2);
        foreach (['build.sh', 'hashes.sh', 'legal_info.py', 'manifest.sh', 'usb_image.py'] as $file)
            copy($root.'/tools/utility-os/'.$file, $this->dir.'/repo/tools/utility-os/'.$file);
        mkdir($this->dir.'/repo/utility-os/package/cmp-rpi-utils', 0755, true);
        foreach (['CMakeLists.txt', 'cmp-rpi-utils.mk'] as $file)
            file_put_contents($this->dir.'/repo/utility-os/package/cmp-rpi-utils/'.$file, 'original '.$file);
        copy($root.'/utility-os/versions.env', $this->versionsPath());
        copy($root.'/utility-os/configs/cmprovision_defconfig', $this->dir.'/repo/utility-os/configs/cmprovision_defconfig');
        $config = $this->dir.'/work/buildroot-2025.02.18/utils/config';
        // The pinned Buildroot helper uses ':' as the sed delimiter when replacing existing strings.
        file_put_contents($config, <<<'SH'
#!/bin/sh
file=$2; shift 2
while [ $# -gt 0 ]; do
    action=$1; key=$2; shift 2
    case "$action" in
        --undefine) sed -i "/^$key=/d" "$file" ;;
        --set-str)
            value=$1; shift
            if grep -q "^$key=" "$file"; then
                sed -i "s:^$key=.*:$key=\"$value\":" "$file"
            else
                printf '%s="%s"\n' "$key" "$value" >> "$file"
            fi ;;
    esac
done
SH);
        chmod($config, 0755);
        $make = <<<'SH'
#!/bin/sh
set -eu
out=$UOS_WORK/output
mkdir -p "$out/build/linux-custom" "$out/images/scriptexecute64" "$out/legal-info"
case "$*" in
    *cmprovision_defconfig*)
        printf '%s\n' 'BR2_LINUX_KERNEL_CUSTOM_TARBALL_LOCATION="https://github.com/raspberrypi/linux/archive/UOS_LINUX_COMMIT/linux-UOS_LINUX_COMMIT.tar.gz"' 'BR2_GLOBAL_PATCH_DIR=""' > "$out/.config" ;;
esac
# Buildroot's linux-custom directory survives reconfigure, including its extracted source.
if [ ! -f "$out/build/linux-custom/source-commit" ]; then
    sed -n 's/^UOS_LINUX_COMMIT=//p' "$UOS_TEST_VERSIONS" > "$out/build/linux-custom/source-commit"
fi
cp "$out/build/linux-custom/source-commit" "$out/images/scriptexecute64/built-source"
# Like cmp-rpi-utils' POST_EXTRACT: Buildroot does not recopy recipes after extraction.
mkdir -p "$out/build/cmp-rpi-utils"
for file in CMakeLists.txt cmp-rpi-utils.mk; do
    if [ ! -f "$out/build/cmp-rpi-utils/$file" ]; then
        cp "$(dirname "$UOS_TEST_VERSIONS")/package/cmp-rpi-utils/$file" "$out/build/cmp-rpi-utils/$file"
    fi
    cp "$out/build/cmp-rpi-utils/$file" "$out/images/scriptexecute64/$file"
done
SH;
        file_put_contents($this->dir.'/bin/make', $make."\n");
        chmod($this->dir.'/bin/make', 0755);
        file_put_contents($this->dir.'/bin/python3', <<<'SH'
#!/bin/sh
case "$*" in *" collect "*) [ ! -f "$UOS_WORK/fail-collection" ] || exit 1 ;; esac
exit 0
SH);
        chmod($this->dir.'/bin/python3', 0755);
        file_put_contents($this->dir.'/cache/keep', 'cached downloads');
    }

    protected function tearDown(): void
    {
        if (isset($this->dir))
            exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    protected function versionsPath(): string
    {
        return $this->dir.'/repo/utility-os/versions.env';
    }

    protected function build(int $expected = 0): void
    {
        $env = ['PATH' => $this->dir.'/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
                'UOS_WORK' => $this->dir.'/work', 'UOS_CACHE' => $this->dir.'/cache',
                'UOS_TEST_VERSIONS' => $this->versionsPath()];
        $prefix = '';
        foreach ($env as $key => $value)
            $prefix .= $key.'='.escapeshellarg($value).' ';
        exec($prefix.'sh '.escapeshellarg($this->dir.'/repo/tools/utility-os/build.sh').' 2>&1', $output, $code);
        $this->assertSame($expected, $code, implode("\n", $output));
        $this->assertSame('cached downloads', file_get_contents($this->dir.'/cache/keep'));
    }

    public function test_changing_a_pin_cannot_reuse_the_previous_extracted_kernel()
    {
        $this->build();
        $versions = file_get_contents($this->versionsPath());
        $versions = preg_replace('/^UOS_LINUX_COMMIT=.*$/m', 'UOS_LINUX_COMMIT='.str_repeat('a', 40), $versions);
        file_put_contents($this->versionsPath(), $versions);

        $this->build();

        $this->assertSame(str_repeat('a', 40)."\n", file_get_contents($this->dir.'/work/output/images/scriptexecute64/built-source'));
        $this->assertSame(str_repeat('a', 40)."\n", file_get_contents($this->dir.'/repo/scriptexecute64/built-source'));
    }

    public function test_unchanged_pins_keep_the_incremental_build()
    {
        $this->build();
        file_put_contents($this->dir.'/work/output/keep', 'incremental output');

        $this->build();

        $this->assertSame('incremental output', file_get_contents($this->dir.'/work/output/keep'));
    }

    public static function recipeInputs(): array
    {
        return [['CMakeLists.txt'], ['cmp-rpi-utils.mk']];
    }

    #[DataProvider('recipeInputs')]
    public function test_recipe_changes_are_applied_with_unchanged_pins(string $file)
    {
        $this->build();
        file_put_contents($this->dir.'/repo/utility-os/package/cmp-rpi-utils/'.$file, 'changed recipe');

        $this->build();

        $this->assertSame('changed recipe', file_get_contents($this->dir.'/repo/scriptexecute64/'.$file));
    }

    public function test_an_output_without_known_pins_is_rebuilt()
    {
        mkdir($this->dir.'/work/output/build/linux-custom', 0755, true);
        file_put_contents($this->dir.'/work/output/build/linux-custom/source-commit', "unknown old source\n");

        $this->build();

        preg_match('/^UOS_LINUX_COMMIT=(.*)$/m', file_get_contents($this->versionsPath()), $match);
        $this->assertSame($match[1]."\n", file_get_contents($this->dir.'/repo/scriptexecute64/built-source'));
    }

    public function test_the_configuration_uses_the_pinned_url_and_hash_directory()
    {
        $this->build();

        preg_match('/^UOS_LINUX_COMMIT=(.*)$/m', file_get_contents($this->versionsPath()), $match);
        $config = file_get_contents($this->dir.'/work/output/.config');
        $this->assertStringContainsString('BR2_LINUX_KERNEL_CUSTOM_TARBALL_LOCATION="https://github.com/raspberrypi/linux/archive/'
            .$match[1].'/linux-'.$match[1].'.tar.gz"', $config);
        $this->assertStringContainsString('BR2_GLOBAL_PATCH_DIR="'.$this->dir.'/repo/utility-os/patches '.$this->dir.'/work/hashes"', $config);
    }

    public function test_a_changed_defconfig_cannot_keep_removed_components_in_the_image()
    {
        $this->build();
        file_put_contents($this->dir.'/work/output/images/scriptexecute64/obsolete', 'previous component');
        file_put_contents($this->dir.'/repo/utility-os/configs/cmprovision_defconfig', "\n# changed package selection\n", FILE_APPEND);

        $this->build();

        $this->assertFileDoesNotExist($this->dir.'/repo/scriptexecute64/obsolete');
    }

    public function test_incomplete_sources_do_not_replace_the_accepted_os()
    {
        $this->build();
        $accepted = file_get_contents($this->dir.'/repo/scriptexecute64/built-source');
        $versions = preg_replace('/^UOS_LINUX_COMMIT=.*$/m', 'UOS_LINUX_COMMIT='.str_repeat('c', 40), file_get_contents($this->versionsPath()));
        file_put_contents($this->versionsPath(), $versions);
        file_put_contents($this->dir.'/work/fail-collection', 'fail');

        $this->build(1);

        $this->assertSame($accepted, file_get_contents($this->dir.'/repo/scriptexecute64/built-source'));
    }
}
