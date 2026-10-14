<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * A cmp-table's header sticks under the top bar (top: var(--bar-h)). Inside a box of its own that scrolls, the header
 * sticks to that box instead and lands a bar's height down, over the rows: such a box is cmp-card-scroll (which moves
 * the header to its top) or the table says in-scroll.
 */
class TableScrollTest extends TestCase
{
    public function test_a_table_in_a_scrolling_box_keeps_its_header_on_top()
    {
        $wrong = [];
        foreach (glob(resource_path('views/{,*/,*/*/}*.blade.php'), GLOB_BRACE) as $file) {
            $lines = file($file);
            foreach ($lines as $n => $line) {
                if (!preg_match('/class="[^"]*\boverflow(-[xy])?-(auto|scroll)\b/', $line))
                    continue;
                // the table this box holds: the next one within a few lines
                foreach (array_slice($lines, $n + 1, 4) as $next)
                    if (preg_match('/<table class="([^"]*\bcmp-table\b[^"]*)"/', $next, $m)) {
                        if (!preg_match('/\bin-scroll\b/', $m[1]))
                            $wrong[] = basename($file).':'.($n + 1);
                        break;
                    }
            }
        }
        $this->assertSame([], $wrong, 'wrap the table in cmp-card-scroll, or give it in-scroll');
    }
}
