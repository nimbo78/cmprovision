<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Dialogs and other fixed overlays open above the sticky top bar (.cmp-topbar), not under it */
class OverlayLayerTest extends TestCase
{
    public function test_fixed_overlays_stack_above_the_top_bar()
    {
        $root = dirname(__DIR__, 2);
        $this->assertSame(1, preg_match('/\.cmp-topbar\s*\{[^}]*z-index:\s*(\d+)/', file_get_contents($root.'/resources/css/app.css'), $bar));

        $under = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/resources/views', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!str_ends_with($file->getFilename(), '.blade.php'))
                continue;
            preg_match_all('/class="([^"]*)"/', file_get_contents($file->getPathname()), $m);
            foreach ($m[1] as $classes) {
                $list = preg_split('/\s+/', trim($classes));
                if (!in_array('fixed', $list, true))
                    continue;
                foreach ($list as $class)
                    if (preg_match('/^z-(\d+)$/', $class, $z) && (int) $z[1] <= (int) $bar[1])
                        $under[] = basename($file->getPathname()).': '.$classes;
            }
        }
        $this->assertSame([], $under, 'fixed overlays at or below the top bar (z-index '.$bar[1].')');
    }
}
