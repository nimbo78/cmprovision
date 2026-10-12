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
    /* Views still on raw colours: each conversion task takes its files off this list (compact web plan, Task 1) */
    const NOT_YET = [
        'api/api-token-manager.blade.php', 'api/index.blade.php',
        'auth/confirm-password.blade.php', 'auth/login.blade.php', 'auth/two-factor-challenge.blade.php',
        'components/action-message.blade.php', 'components/action-section.blade.php', 'components/authentication-card.blade.php',
        'components/banner.blade.php', 'components/button.blade.php', 'components/checkbox.blade.php',
        'components/confirmation-modal.blade.php', 'components/danger-button.blade.php', 'components/dialog-modal.blade.php',
        'components/dropdown-link.blade.php', 'components/dropdown.blade.php', 'components/form-section.blade.php',
        'components/input-error.blade.php', 'components/input.blade.php', 'components/label.blade.php',
        'components/modal.blade.php', 'components/nav-link.blade.php', 'components/responsive-nav-link.blade.php',
        'components/secondary-button.blade.php', 'components/section-border.blade.php', 'components/section-title.blade.php',
        'components/switchable-team.blade.php', 'components/validation-errors.blade.php',
        'dashboard.blade.php', 'layouts/app.blade.php', 'layouts/guest.blade.php',
        'livewire/addimage.blade.php', 'livewire/addstaticip.blade.php', 'livewire/cm-card.blade.php', 'livewire/cms.blade.php',
        'livewire/editlabel.blade.php', 'livewire/editproject.blade.php', 'livewire/editscript.blade.php',
        'livewire/firmware.blade.php', 'livewire/images.blade.php', 'livewire/labels.blade.php',
        'livewire/notification-settings.blade.php', 'livewire/ports.blade.php', 'livewire/projects.blade.php',
        'livewire/provisioning-log.blade.php', 'livewire/provisioning-status.blade.php', 'livewire/scripts.blade.php',
        'livewire/settings.blade.php', 'livewire/switch-settings.blade.php', 'livewire/switch-snmp-hints.blade.php',
        'livewire/uplink-indicator.blade.php', 'livewire/viewlog.blade.php', 'navigation-menu.blade.php',
        'profile/logout-other-browser-sessions-form.blade.php', 'profile/show.blade.php',
        'profile/two-factor-authentication-form.blade.php', 'profile/update-profile-information-form.blade.php',
    ];

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

    /** Raw colours in a template: palette utilities (with any variant prefix), dark: variants, hex colours in style */
    public static function problems(string $blade): array
    {
        preg_match_all('/(?<![\w-])(?:[a-z-]+:)*'.self::UTILITIES.'-'.self::PALETTE.'(?:-\d{2,3})?(?:\/\d+)?(?![\w-])/', $blade, $classes);
        preg_match_all('/(?<![\w-])dark:[\w\/\[\]\.-]+/', $blade, $dark);
        preg_match_all('/style="[^"]*#[0-9a-fA-F]{3,8}/', $blade, $hex);
        return array_values(array_unique(array_merge($classes[0], $dark[0], $hex[0])));
    }

    #[DataProvider('views')]
    public function test_views_use_theme_tokens_only(string $view)
    {
        $problems = self::problems(file_get_contents(resource_path('views/'.$view)));
        if (in_array($view, self::NOT_YET, true)) {
            $this->assertNotEmpty($problems, "$view has no raw colours left: take it off TokenGuardTest::NOT_YET");
            return;
        }
        $this->assertSame([], $problems, "$view uses raw colours");
    }

    public function test_the_list_names_only_views_that_exist()
    {
        $missing = array_filter(self::NOT_YET, function ($view) { return !is_file(self::root().$view); });
        $this->assertSame([], array_values($missing));
    }

    public function test_the_guard_sees_what_it_should()
    {
        $this->assertSame(['bg-white', 'hover:text-gray-700', 'dark:bg-gray-800', 'style="color: #b45309'],
            self::problems('<div class="bg-white hover:text-gray-700 dark:bg-gray-800 text-fg bg-panel border-transparent text-sm" style="color: #b45309">'));
        $this->assertSame([], self::problems('<td class="text-fg-muted bg-neg-bg ring-pline shadow-pop">'));
    }
}
