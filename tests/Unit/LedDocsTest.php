<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** docs/leds.md: its figures are there, are SVG, and are what docs/leds/build_diagrams.py builds from the LED code now */
class LedDocsTest extends TestCase
{
    protected function path(string $path): string
    {
        return dirname(__DIR__, 2).'/'.$path;
    }

    /** The figures docs/leds.md shows: leds/<name>.svg */
    protected function figures(): array
    {
        preg_match_all('#!\[[^\]]*\]\(leds/([a-z-]+\.svg)\)#', file_get_contents($this->path('docs/leds.md')), $m);
        return $m[1];
    }

    public function test_every_figure_of_the_doc_is_there_and_is_svg()
    {
        $this->assertCount(5, $this->figures());
        foreach ($this->figures() as $figure)
        {
            $svg = @simplexml_load_file($this->path('docs/leds/'.$figure));
            $this->assertNotFalse($svg, $figure.' is well-formed XML');
            $this->assertSame('svg', $svg->getName(), $figure);
        }
    }

    public function test_the_figures_are_what_the_script_builds_from_the_code()
    {
        // the script reads the timings from ledpattern.c and leds.blade.php: a change there needs the figures built again
        $python = trim((string) shell_exec('command -v python3 2>/dev/null'));
        if ($python === '')
            $this->markTestSkipped('needs python3');
        $out = sys_get_temp_dir().'/cmprovision-leds-'.uniqid();
        mkdir($out);
        try
        {
            exec(escapeshellarg($python).' -I '.escapeshellarg($this->path('docs/leds/build_diagrams.py')).' '.escapeshellarg($out).' 2>&1', $output, $code);
            $this->assertSame(0, $code, implode("\n", $output));
            foreach ($this->figures() as $figure)
                $this->assertSame(str_replace("\r\n", "\n", file_get_contents($this->path('docs/leds/'.$figure))),
                    file_get_contents($out.'/'.$figure), $figure.' is out of date: run python docs/leds/build_diagrams.py');
        }
        finally
        {
            array_map('unlink', glob($out.'/*') ?: []);
            rmdir($out);
        }
    }
}
