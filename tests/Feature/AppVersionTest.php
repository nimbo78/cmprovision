<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\AppVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The version and commit the user menu names: git archive fills them in (export-subst), a working copy says dev */
class AppVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_release_shows_its_version_and_commit()
    {
        config(['version.describe' => 'v2.4.1', 'version.commit' => '3f3b4bf']);
        $this->assertSame('2.4.1 · 3f3b4bf', AppVersion::text());
        config(['version.describe' => 'v2.4.1-3-g8d0e3ed', 'version.commit' => '8d0e3ed']);
        $this->assertSame('2.4.1+3 · 8d0e3ed', AppVersion::text(), 'three commits after the tag');
    }

    public function test_a_working_copy_says_dev()
    {
        config(['version.describe' => '$Format:%(describe)$', 'version.commit' => '$Format:%h$']);
        $this->assertSame('dev', AppVersion::text());
    }

    public function test_git_archive_fills_the_version_in()
    {
        $this->assertMatchesRegularExpression('#^/?config/version\.php\s+export-subst\s*$#m', file_get_contents(base_path('.gitattributes')));
        $source = file_get_contents(config_path('version.php'));
        $this->assertStringContainsString('$Format:%(describe)$', $source);
        $this->assertStringContainsString('$Format:%h$', $source);
    }

    public function test_the_user_menu_names_the_version()
    {
        config(['version.describe' => 'v2.4.1', 'version.commit' => '3f3b4bf']);
        $this->actingAs(User::factory()->create())->get('/dashboard')->assertOk()->assertSee('cmprovision 2.4.1 · 3f3b4bf');
    }
}
