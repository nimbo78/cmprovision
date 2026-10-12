<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Fields take the theme: cmp-field (its focus outline and colours) rather than the old style with a light-theme
 * shadow and the forms plugin's blue ring; the editors of shell scripts, label templates and EEPROM settings are
 * monospaced, as code is.
 */
class FormFieldsTest extends TestCase
{
    protected function source($name)
    {
        return file_get_contents(dirname(__DIR__, 2).'/resources/views/'.$name);
    }

    public function test_no_view_keeps_the_old_field_style()
    {
        $old = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2).'/resources/views', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file)
            if (str_ends_with($file->getFilename(), '.blade.php') && str_contains(file_get_contents($file->getPathname()), 'shadow appearance-none border rounded'))
                $old[] = $file->getFilename();
        sort($old);
        $this->assertSame([], $old);
    }

    public function test_code_editors_are_monospaced_fields()
    {
        foreach (['livewire/editscript.blade.php' => 'script', 'livewire/editlabel.blade.php' => 'template', 'livewire/editproject.blade.php' => 'eeprom_settings'] as $view => $id) {
            $this->assertSame(1, preg_match('/<textarea[^>]*id="'.$id.'"[^>]*class="([^"]*)"/', $this->source($view), $m), $view);
            $classes = preg_split('/\s+/', $m[1]);
            $this->assertContains('cmp-field', $classes, $view);
            $this->assertContains('font-mono', $classes, $view);
        }
    }
}
