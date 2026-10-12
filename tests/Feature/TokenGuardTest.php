<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Views name colours by role (theme tokens, tailwind.config.js), never by palette: the Appearance style and mode
 * then reach every page. scriptexecute/ renders shell, not HTML.
 */
class TokenGuardTest extends TestCase
{
    const PALETTE = '(?:white|black|slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)';
    const UTILITIES = '(?:bg|text|border|border-[trblxy]|ring|ring-offset|divide|outline|placeholder|from|via|to|fill|stroke|decoration|accent|caret|shadow)';

    /* PHPUnit asks data providers before Laravel boots: the views' path from this file, not resource_path() */
    protected static function root(): string
    {
        return dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'views'.DIRECTORY_SEPARATOR;
    }

    public static function views(): array
    {
        $root = self::root();
        $views = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $view = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root)));
            if (str_ends_with($view, '.blade.php') && !str_starts_with($view, 'scriptexecute/'))
                $views[$view] = [$view];
        }
        ksort($views);
        return $views;
    }

    const NAMED = '(?:white|black|red|green|blue|yellow|orange|purple|pink|gray|grey|silver|navy|teal|maroon|olive|lime|aqua|fuchsia)';

    /** Raw colours in a template: palette utilities (with any variant prefix), arbitrary colour values (bg-[#fff]; a
        var() is a token), dark: variants, and hex, rgb()/hsl() or named colours in a style with either quote */
    public static function problems(string $blade): array
    {
        preg_match_all('/(?<![\w-])(?:[a-z-]+:)*'.self::UTILITIES.'-'.self::PALETTE.'(?:-\d{2,3})?(?:\/\d+)?(?![\w-])/', $blade, $classes);
        preg_match_all('/(?<![\w-])(?:[a-z-]+:)*'.self::UTILITIES.'-\[(?:color:)?(?:#|rgba?\(|hsla?\()[^\]]*\]/', $blade, $arbitrary);
        preg_match_all('/(?<![\w-])dark:[\w\/\[\]\.-]+/', $blade, $dark);
        preg_match_all('/style=["\'][^"\']*#[0-9a-fA-F]{3,8}/', $blade, $hex);
        preg_match_all('/style=["\'][^"\']*(?:rgb|hsl)a?\(/', $blade, $functions);
        preg_match_all('/style=["\'][^"\']*(?:color|background|border|fill|stroke|outline)[\w-]*\s*:\s*'.self::NAMED.'\b/', $blade, $named);
        return array_values(array_unique(array_merge($classes[0], $arbitrary[0], $dark[0], $hex[0], $functions[0], $named[0])));
    }

    #[DataProvider('views')]
    public function test_views_use_theme_tokens_only(string $view)
    {
        $this->assertSame([], self::problems(file_get_contents(resource_path('views/'.$view))), "$view uses raw colours");
    }

    public function test_the_guard_sees_what_it_should()
    {
        $this->assertSame(['bg-white', 'hover:text-gray-700', 'dark:bg-gray-800', 'style="color: #b45309'],
            self::problems('<div class="bg-white hover:text-gray-700 dark:bg-gray-800 text-fg bg-panel border-transparent text-sm" style="color: #b45309">'));
        $this->assertSame([], self::problems('<td class="text-fg-muted bg-neg-bg ring-pline shadow-pop">'));
    }

    public function test_the_guard_sees_arbitrary_values_and_colours_in_any_style()
    {
        $this->assertSame(['bg-[#fff]', 'text-[rgb(10,20,30)]', "style='color: #b45309", 'style="border-color: rgb(', 'style="background: white'],
            self::problems('<div class="bg-[#fff] text-[rgb(10,20,30)]" style=\'color: #b45309\'><p style="border-color: rgb(1,2,3)"></p><i style="background: white"></i></div>'));
        $this->assertSame([], self::problems('<div class="text-[color:var(--on-btn)] gap-[var(--gap)] w-[18px]" style="background: var(--scrim); width: 64%">'));
    }
}
