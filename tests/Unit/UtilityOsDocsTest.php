<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** The utility OS is documented where people look (2.7.0): docs/utility-os.md names every script and the switch, and
    CLAUDE.md no longer says the boot files are ready-made binaries without sources */
class UtilityOsDocsTest extends TestCase
{
    protected function file(string $path): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/'.$path);
    }

    public function test_the_guide_names_the_scripts_the_switch_and_the_sources()
    {
        $doc = $this->file('docs/utility-os.md');
        foreach (['tools/utility-os/build.sh', 'tools/utility-os/update.sh', 'tools/utility-os/test.sh', 'utility-os/versions.env',
                  '.github/workflows/utility-os.yml', 'etc/boot', 'sudo -u www-data ln -sfn ../scriptexecute /var/lib/cmprovision/etc/boot',
                  'legal-info', 'bootfiles.bin', 'MANIFEST', 'Settings → Utility OS'] as $needle)
            $this->assertStringContainsString($needle, $doc, $needle);
    }

    public function test_claude_md_describes_both_systems()
    {
        $claude = $this->file('CLAUDE.md');
        $this->assertStringNotContainsString('исходников в репозитории нет', $claude);
        foreach (['## Утилитарная ОС', 'scriptexecute64/', 'utility-os/versions.env', 'tools/boot-link', 'cms.utility_os',
                  'utility-os/board/cmprovision/cmdline.txt'] as $needle)
            $this->assertStringContainsString($needle, $claude, $needle);
    }

    public function test_readme_and_roadmap_know_the_release()
    {
        $this->assertStringContainsString('Utility OS', $this->file('README.md'));
        $this->assertStringContainsString('2.7.0', $this->file('ROADMAP.md'));
    }
}
