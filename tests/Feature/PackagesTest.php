<?php

namespace Tests\Feature;

use App\Http\Livewire\Scripts;
use App\Models\Package;
use App\Models\Project;
use App\Models\User;
use App\Services\DebPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * .deb packages for the OS check (2.6.0): uploaded on the Scripts page, read by dpkg-deb (a stand-in here), kept in
 * public/uploads/packages with their SHA256, chosen in projects, deleted only while no project installs them.
 */
class PackagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // dpkg-deb's stand-in: any file is a package unless it says it is not one
        $this->app->instance(DebPackage::class, new class extends DebPackage {
            public function fields(string $path): ?array
            {
                return str_contains((string) file_get_contents($path), 'not a package') ? null
                    : ['package' => 'wlanpi-extra', 'version' => '1.2.3', 'architecture' => 'arm64'];
            }
        });
    }

    protected function tearDown(): void
    {
        foreach (Package::all() as $package)
            @unlink($package->path());
        parent::tearDown();
    }

    protected function upload($name, $content)
    {
        return $this->post('/addPackage', ['package' => UploadedFile::fake()->createWithContent($name, $content)]);
    }

    public function test_an_uploaded_package_is_kept_with_what_it_says_about_itself()
    {
        $this->actingAs(User::factory()->create());

        $this->upload('wlanpi-extra_1.2.3_arm64.deb', 'deb bytes')->assertRedirect(route('scripts'))
            ->assertSessionHas('message', 'Package wlanpi-extra 1.2.3 (arm64) added.');

        $package = Package::firstOrFail();
        $this->assertSame(['wlanpi-extra_1.2.3_arm64.deb', 'wlanpi-extra', '1.2.3', 'arm64', 9, hash('sha256', 'deb bytes')],
            [$package->filename, $package->deb_package, $package->deb_version, $package->deb_architecture, $package->size, $package->sha256]);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}\.deb$/', $package->filename_on_server);
        $this->assertSame('deb bytes', file_get_contents($package->path()));
    }

    public function test_only_deb_files_are_taken()
    {
        $this->actingAs(User::factory()->create());

        $this->upload('notes.txt', 'deb bytes')->assertRedirect(route('scripts'))
            ->assertSessionHas('message', 'Only .deb packages can be uploaded.');
        $this->assertSame(0, Package::count());
    }

    public function test_a_file_dpkg_does_not_read_is_not_kept()
    {
        $this->actingAs(User::factory()->create());
        $before = glob(public_path('uploads/packages/*')) ?: [];

        $this->upload('broken.deb', 'not a package')->assertRedirect(route('scripts'))
            ->assertSessionHas('message', "'broken.deb' is not a Debian package.");
        $this->assertSame(0, Package::count());
        $this->assertSame($before, glob(public_path('uploads/packages/*')) ?: []);
    }

    public function test_the_scripts_page_lists_the_packages()
    {
        $this->actingAs(User::factory()->create());
        $this->upload('wlanpi-extra_1.2.3_arm64.deb', 'deb bytes');

        Livewire::test(Scripts::class)->assertSee('Packages')->assertSee('wlanpi-extra')->assertSee('1.2.3')
            ->assertSee('arm64')->assertSee('wlanpi-extra_1.2.3_arm64.deb')->assertSee(substr(hash('sha256', 'deb bytes'), 0, 12));
    }

    public function test_a_package_no_project_installs_can_be_deleted()
    {
        $this->actingAs(User::factory()->create());
        $this->upload('wlanpi-extra_1.2.3_arm64.deb', 'deb bytes');
        $package = Package::firstOrFail();

        Livewire::test(Scripts::class)->call('deletePackage', $package->id)->assertSee('Package wlanpi-extra deleted.');

        $this->assertSame(0, Package::count());
        $this->assertFileDoesNotExist($package->path());
    }

    public function test_a_package_a_project_installs_stays()
    {
        $this->actingAs(User::factory()->create());
        $this->upload('wlanpi-extra_1.2.3_arm64.deb', 'deb bytes');
        $package = Package::firstOrFail();
        $project = Project::create(['name' => '78', 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'label_moment' => 'never',
            'verify' => false, 'os_check' => true]);
        $project->packages()->attach($package->id);

        Livewire::test(Scripts::class)->call('deletePackage', $package->id)
            ->assertSee("Cannot delete: package wlanpi-extra is installed by projects: '78'");

        $this->assertSame(1, Package::count());
        $this->assertFileExists($package->path());
    }

    public function test_only_a_signed_in_user_uploads()
    {
        $this->upload('wlanpi-extra_1.2.3_arm64.deb', 'deb bytes')->assertRedirect(route('login'));
        $this->assertSame(0, Package::count());
    }
}
