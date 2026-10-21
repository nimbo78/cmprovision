<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class PhpSecurityRequirementsTest extends TestCase
{
    protected function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public function test_composer_accepts_fixed_branches_and_rejects_vulnerable_patch_releases()
    {
        if (!class_exists(\Composer\Semver\Semver::class)) {
            $autoload = '/usr/share/php/Composer/Semver/autoload.php';
            if (!is_file($autoload))
                $this->markTestSkipped('Debian Composer is needed to evaluate the real Composer constraint');
            require_once $autoload;
        }

        $composer = json_decode(file_get_contents($this->root().'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach (['8.2.35' => false, '8.3.35' => false, '8.3.36' => true, '8.3.37' => true,
                  '8.4.26' => false, '8.4.27' => true, '8.4.28' => true, '8.5.11' => false,
                  '8.5.12' => true, '8.6.0' => true, '9.0.0' => false] as $version => $expected)
            $this->assertSame($expected, \Composer\Semver\Semver::satisfies($version, $composer['require']['php']), $version);

        $lock = json_decode(file_get_contents($this->root().'/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($composer['require']['php'], $lock['platform']['php']);
        $this->assertSame($composer['config']['platform']['php'], $lock['platform-overrides']['php']);
        $this->assertTrue(\Composer\Semver\Semver::satisfies($composer['config']['platform']['php'], $composer['require']['php']));
    }

    public function test_dpkg_requires_both_patched_cli_and_fpm_even_when_metapackages_are_installed()
    {
        if (!is_executable('/usr/bin/dpkg-checkbuilddeps'))
            $this->markTestSkipped('Debian dpkg-dev is needed to evaluate the real package dependencies');

        preg_match('/^Depends:\s*(.*(?:\n[ \t]+.*)*)/m', file_get_contents($this->root().'/debian/control'), $match);
        $requirements = array_values(array_filter(explode(',', $match[1]), fn ($s) => preg_match('/php8\.[0-9]+-(cli|fpm)/', $s)));
        $this->assertCount(2, $requirements, 'CLI and FPM have their own versioned dependencies');
        $dependencies = implode(',', $requirements);
        $directory = sys_get_temp_dir().'/cmprovision-php-deps-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {
            foreach ([['8.3','8.3.35','8.3.35',false], ['8.3','8.3.36','8.3.36',true],
                      ['8.4','8.4.26','8.4.26',false], ['8.4','8.4.27','8.4.27',true],
                      ['8.4','8.4.27','8.4.26',false], ['8.4','8.4.26','8.4.27',false],
                      ['8.5','8.5.11','8.5.11',false], ['8.5','8.5.12','8.5.12',true]] as [$branch,$cli,$fpm,$expected]) {
                $status = '';
                foreach (['php'.$branch.'-cli' => $cli, 'php'.$branch.'-fpm' => $fpm,
                          'php-cli' => '2:8.4+96', 'php-fpm' => '2:8.4+96'] as $name => $version)
                    $status .= "Package: $name\nStatus: install ok installed\nArchitecture: all\nVersion: $version\n\n";
                file_put_contents($directory.'/status', $status);
                $process = new Process(['/usr/bin/dpkg-checkbuilddeps', '--admindir='.$directory, '-I', '-d',
                    $dependencies, $this->root().'/debian/control'], env: ['LC_ALL' => 'C']);
                $process->run();
                $this->assertContains($process->getExitCode(), [0,1], $process->getErrorOutput());
                $this->assertSame($expected, $process->isSuccessful(), "CLI $cli / FPM $fpm: ".$process->getErrorOutput());
            }
        } finally {
            unlink($directory.'/status');
            rmdir($directory);
        }
    }
}
