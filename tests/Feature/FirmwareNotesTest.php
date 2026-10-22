<?php

namespace Tests\Feature;

use App\Http\Livewire\Firmwares;
use App\Http\Livewire\Projects;
use App\Models\Project;
use App\Services\FirmwareNotes;
use App\Services\FirmwareUpdater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** What changed between the EEPROM images: the release notes of rpi-eeprom kept beside the files and shown with them */
class FirmwareNotesTest extends TestCase
{
    use RefreshDatabase;

    protected $dir;
    protected $systemDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/cmprovision-fwnotes-'.uniqid();
        $this->systemDir = $this->dir.'-system';
        mkdir($this->dir, 0755, true);
        config(['cmprovision.firmware_dir' => $this->dir, 'cmprovision.system_firmware_dir' => $this->systemDir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        File::deleteDirectory($this->systemDir);
        parent::tearDown();
    }

    protected function fixture(): string
    {
        return file_get_contents(__DIR__.'/../fixtures/firmware/release-notes.md');
    }

    protected function storeNotes(): FirmwareNotes
    {
        file_put_contents($this->dir.'/release-notes.md', $this->fixture());

        return new FirmwareNotes;
    }

    protected function image(string $channel, string $name, ?int $age = null): void
    {
        @mkdir($this->dir.'/'.$channel, 0755, true);
        file_put_contents($this->dir.'/'.$channel.'/'.$name, 'img');
        if ($age !== null) {
            touch($this->dir.'/'.$channel.'/'.$name, time() - $age);
        }
    }

    public function test_an_entry_has_its_date_title_channel_and_the_changes_as_a_list()
    {
        $entries = FirmwareNotes::parse($this->fixture());

        $this->assertSame(['2026-10-22', '2026-10-15', '2026-09-27', '2026-09-23', '2026-09-12', '2024-08-14', '2024-04-15', '2024-04-15'], array_column($entries, 'date'));
        $first = $entries[0];
        $this->assertSame('Disable HTTP redirects', $first['title']);
        $this->assertSame('latest', $first['channel']);
        $this->assertSame('', $first['intro']);
        $this->assertSame('Improve HTTP status handling', $first['items'][0]['title']);
        $this->assertSame("The code currently only handles specific responses and doesn't treat\nan unexpected response as an error.", $first['items'][0]['detail']);
        $this->assertCount(2, $first['items']);
        $this->assertStringContainsString('HTTP_ALLOW_REDIRECTS=1', $first['items'][1]['detail']);
    }

    public function test_a_note_without_a_list_is_its_intro()
    {
        $entry = FirmwareNotes::parse($this->fixture())[1];

        $this->assertSame('2711: recovery.bin: SDRAM init robustness improvements', $entry['title']);
        $this->assertSame([], $entry['items']);
        $this->assertSame("Add some robustness improvements (so far theoretical) to the low-speed memory\ninit code used in recovery.bin.", $entry['intro']);
    }

    public function test_the_old_heading_shapes_are_read_too_and_an_undated_heading_ends_an_entry()
    {
        $entries = FirmwareNotes::parse($this->fixture());

        $this->assertSame(['(recovery.bin) -  Add support for OTP metadata', 'latest'], [$entries[5]['title'], $entries[5]['channel']]);
        $this->assertSame(['Fix tryboot mode in secure-boot', 'default'], [$entries[6]['title'], $entries[6]['channel']]);
        $this->assertSame(['Fix tryboot mode in secure-boot', 'stable'], [$entries[7]['title'], $entries[7]['channel']]);
        $this->assertStringNotContainsString('undated', json_encode($entries), 'the body of an undated heading belongs to nobody');
        $this->assertSame([], FirmwareNotes::parse("# only a title\n\nno entries here\n"));
    }

    public function test_a_file_gets_the_notes_of_its_date_and_where_it_was_promoted()
    {
        $notes = $this->storeNotes();

        $file = $notes->forFile('pieeprom-2026-09-23.bin');

        $this->assertSame('2026-09-23', $file['date']);
        $this->assertSame(['Check MFG version when updating'], array_column($file['changes'], 'title'));
        $this->assertSame(['2026-09-27'], array_column($file['promotions'], 'date'));
        $this->assertSame('Promote 2026-09-23 to the default release', $file['promotions'][0]['title']);
        $this->assertSame([], $notes->forFile('pieeprom-2026-10-22.bin')['promotions']);
    }

    public function test_a_date_without_notes_or_a_name_without_a_date_or_no_notes_file_says_nothing()
    {
        $notes = $this->storeNotes();

        $this->assertSame([], $notes->forFile('pieeprom-2025-01-01.bin')['changes']);
        $this->assertNull($notes->forFile('recovery.bin'));
        $this->assertNull((new FirmwareNotes($this->dir.'/missing.md'))->forFile('pieeprom-2026-09-23.bin'));
        $this->assertNull((new FirmwareNotes($this->dir.'/missing.md'))->latestDate());
    }

    public function test_what_changed_between_two_images_is_everything_after_the_first_up_to_the_second()
    {
        $notes = $this->storeNotes();

        $between = $notes->between('2026-09-12', '2026-10-15');

        $this->assertStringContainsString('SDRAM', $between[0]['title']);
        $this->assertSame(['2026-10-15', '2026-09-23'], array_column($between, 'date'), 'newest first, no promotions, the first image itself is not in it');
        $this->assertSame('2026-10-22', $notes->latestDate());
    }

    // --- the store gets the notes together with the images

    protected function fakeNotes(string $body, int $status = 200): void
    {
        Http::fake(function (Request $request) use ($body, $status) {
            if ($request->url() === FirmwareUpdater::NOTES_URL) {
                return Http::response($body, $status);
            }

            return Http::response([], 200);
        });
    }

    public function test_the_update_keeps_the_notes_beside_the_images()
    {
        $this->fakeNotes($this->fixture());

        $result = (new FirmwareUpdater)->update();

        $this->assertSame($this->fixture(), file_get_contents($this->dir.'/release-notes.md'));
        $this->assertSame('updated', $result['notes']);
        $this->assertSame([], $result['errors']);
        $this->assertSame('unchanged', (new FirmwareUpdater)->update()['notes']);
    }

    public function test_the_package_notes_work_without_network()
    {
        mkdir($this->systemDir, 0755, true);
        file_put_contents($this->systemDir.'/release-notes.md', $this->fixture());
        Http::fake(fn () => Http::response('offline', 503));

        $result = (new FirmwareUpdater)->update();

        $this->assertSame($this->fixture(), file_get_contents($this->dir.'/release-notes.md'));
        $this->assertSame('updated', $result['notes']);
    }

    protected function olderNotes(): string
    {
        return preg_replace('/## 2026-10-22.*?(?=## 2026-10-15)/s', '', $this->fixture());
    }

    public function test_an_older_download_never_replaces_newer_notes()
    {
        file_put_contents($this->dir.'/release-notes.md', $this->fixture());
        $this->fakeNotes($this->olderNotes());

        $this->assertSame('unchanged', (new FirmwareUpdater)->update()['notes']);
        $this->assertSame($this->fixture(), file_get_contents($this->dir.'/release-notes.md'));
    }

    public function test_of_the_package_and_github_the_newer_notes_are_kept()
    {
        mkdir($this->systemDir, 0755, true);
        file_put_contents($this->systemDir.'/release-notes.md', $this->olderNotes());
        $this->fakeNotes($this->fixture());

        $this->assertSame('updated', (new FirmwareUpdater)->update()['notes']);
        $this->assertSame($this->fixture(), file_get_contents($this->dir.'/release-notes.md'), 'GitHub is newer than the package');
    }

    public function test_something_that_is_not_release_notes_is_not_stored()
    {
        $this->fakeNotes('<html>Rate limit exceeded</html>');
        $this->assertSame('unavailable', (new FirmwareUpdater)->update()['notes']);
        $this->assertFileDoesNotExist($this->dir.'/release-notes.md');

        $this->fakeNotes('gone', 404);
        $this->assertSame('unavailable', (new FirmwareUpdater)->update()['notes']);
    }

    public function test_an_unreachable_notes_source_does_not_fail_the_images()
    {
        Http::fake(function (Request $request) {
            if ($request->url() === FirmwareUpdater::NOTES_URL) {
                throw new \Illuminate\Http\Client\ConnectionException('cURL error 6');
            }

            return Http::response([], 200);
        });

        $result = (new FirmwareUpdater)->update();

        $this->assertSame('unavailable', $result['notes']);
        $this->assertSame([], $result['errors']);
    }

    // --- the pages

    public function test_the_firmware_page_shows_what_each_image_changed_and_marks_a_new_one()
    {
        $this->storeNotes();
        $this->image('latest', 'pieeprom-2026-10-22.bin', 3600);
        $this->image('default', 'pieeprom-2026-09-23.bin', 40 * 86400);

        Livewire::test(Firmwares::class)
            ->assertSee('Disable HTTP redirects')
            ->assertSee('Improve HTTP status handling')
            ->assertSee('Check MFG version when updating')
            ->assertSee('Promoted: 2026-09-27')
            ->assertSeeHtmlInOrder(['pieeprom-2026-10-22.bin', 'cmp-chip', 'new'])
            ->assertSeeHtml('data-new="1"')
            ->assertSeeHtml('data-new="0"');
    }

    public function test_the_firmware_page_says_when_it_has_no_notes_yet()
    {
        $this->image('latest', 'pieeprom-2026-10-22.bin');

        Livewire::test(Firmwares::class)->assertSee('Release notes arrive with the next check');
    }

    public function test_the_firmware_page_names_a_notes_update_in_its_message()
    {
        $this->fakeNotes($this->fixture());

        Livewire::test(Firmwares::class)->call('update')->assertSee('Release notes updated.');
    }

    public function test_choosing_a_firmware_shows_what_it_changes_against_the_projects_current_one()
    {
        $this->storeNotes();
        $this->image('default', 'pieeprom-2026-09-12.bin');
        $this->image('latest', 'pieeprom-2026-10-22.bin');
        $project = Project::create(['name' => 'p', 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'label_moment' => 'never', 'eeprom_firmware' => 'default/pieeprom-2026-09-12.bin']);

        Livewire::test(Projects::class)
            ->call('edit', $project->id)
            ->assertSee('Same image as now')   // nothing else chosen yet
            ->set('firmware', 'latest/pieeprom-2026-10-22.bin')
            ->assertSee('Changes since 2026-09-12')
            ->assertSee('Disable HTTP redirects')
            ->assertSee('Check MFG version when updating')
            ->assertSee('SDRAM init robustness improvements')
            ->assertDontSee('Cache the EEPROM config for start.elf')
            ->set('firmware', 'default/pieeprom-2026-09-12.bin')
            ->assertSee('Same image as now');
    }

    public function test_an_older_choice_says_what_the_current_one_has_that_it_lacks()
    {
        $this->storeNotes();
        $this->image('default', 'pieeprom-2026-09-12.bin');
        $this->image('latest', 'pieeprom-2026-10-22.bin');
        $project = Project::create(['name' => 'p', 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'label_moment' => 'never', 'eeprom_firmware' => 'latest/pieeprom-2026-10-22.bin']);

        Livewire::test(Projects::class)
            ->call('edit', $project->id)
            ->set('firmware', 'default/pieeprom-2026-09-12.bin')
            ->assertSee('Older than the current image')
            ->assertSee('Disable HTTP redirects');
    }

    public function test_a_new_project_sees_the_notes_of_the_image_it_picks()
    {
        $this->storeNotes();
        $this->image('latest', 'pieeprom-2026-10-22.bin');

        Livewire::test(Projects::class)
            ->call('create')
            ->assertDontSee('Disable HTTP redirects')
            ->set('firmware', 'latest/pieeprom-2026-10-22.bin')
            ->assertSee('What this image changes')
            ->assertSee('Disable HTTP redirects');
    }
}
