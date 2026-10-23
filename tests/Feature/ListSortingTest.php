<?php

namespace Tests\Feature;

use App\Http\Livewire\Images;
use App\Http\Livewire\Labels;
use App\Http\Livewire\Projects;
use App\Http\Livewire\Scripts;
use App\Http\Livewire\UsbDiagnostics;
use App\Models\Image;
use App\Models\Label;
use App\Models\Project;
use App\Models\Script;
use App\Models\UsbDiagnosticReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A click on the header of a column sorts the lists of Images, Projects, Scripts, Labels and USB diagnostics (2.18.0, the same way as
 * the Dashboard and CMs: ColumnSort). The names have no slash: the text of an update of a Livewire test is JSON.
 */
class ListSortingTest extends TestCase
{
    use RefreshDatabase;

    protected $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file)
            if (is_file($file))
                unlink($file);
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function image(string $name, int $bytes): Image
    {
        $image = new Image;
        $image->filename = $name;
        $image->filename_extension = 'gz';
        $image->filename_on_server = 'sort-'.uniqid().'.gz';
        $image->sha256 = str_repeat('a', 64);
        $image->save();
        file_put_contents($image->imagepath(), str_repeat('x', $bytes));
        $this->files[] = $image->imagepath();
        return $image;
    }

    public function test_images_go_by_name_and_a_click_sorts_by_size_the_biggest_first()
    {
        $this->image('b-image.img.gz', 300);
        $this->image('a-image.img.gz', 100);
        $this->image('c-image.img.gz', 200);
        $page = Livewire::test(Images::class)->assertSeeInOrder(['a-image', 'b-image', 'c-image']);

        $page->call('sortBy', 'size')->assertSet('sort', 'size')->assertSet('dir', 'desc')->assertSeeInOrder(['b-image', 'c-image', 'a-image'])
            ->assertSeeHtml('aria-sort="descending"');
        $page->call('sortBy', 'size')->assertSet('dir', 'asc')->assertSeeInOrder(['a-image', 'c-image', 'b-image']);
        $page->call('sortBy', 'size')->assertSet('sort', '')->assertSeeInOrder(['a-image', 'b-image', 'c-image']);
        $page->call('sortBy', 'filename')->call('sortBy', 'filename')->assertSet('dir', 'desc')->assertSeeInOrder(['c-image', 'b-image', 'a-image']);
    }

    public function test_an_image_whose_file_is_gone_is_last_by_size_either_way()
    {
        $this->image('a-image.img.gz', 100);
        $gone = $this->image('b-image.img.gz', 200);
        unlink($gone->imagepath());
        Livewire::test(Images::class)->call('sortBy', 'size')->assertSeeInOrder(['a-image', 'b-image'])
            ->call('sortBy', 'size')->assertSeeInOrder(['a-image', 'b-image']);
    }

    protected function project(string $name, ?Image $image): Project
    {
        return Project::create(['name' => $name, 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'image_id' => $image?->id,
            'label_moment' => 'never', 'verify' => false, 'os_check' => false]);
    }

    public function test_projects_go_by_name_and_a_click_sorts_by_the_image_with_those_that_have_none_last()
    {
        $this->project('p-one', $this->image('z-image.img.gz', 10));
        $this->project('p-two', $this->image('a-image.img.gz', 10));
        $this->project('p-none', null);
        $page = Livewire::test(Projects::class)->assertSeeInOrder(['p-none', 'p-one', 'p-two']);

        $page->call('sortBy', 'image')->assertSeeInOrder(['p-two', 'p-one', 'p-none'])->assertSeeHtml('aria-sort="ascending"');
        $page->call('sortBy', 'image')->assertSeeInOrder(['p-one', 'p-two', 'p-none']);
        $page->call('sortBy', 'name')->call('sortBy', 'name')->assertSet('dir', 'desc')->assertSeeInOrder(['p-two', 'p-one', 'p-none']);
    }

    public function test_scripts_sort_by_type_and_by_priority_as_a_number()
    {
        foreach ([['s-a', 'postinstall', 9], ['s-b', 'preinstall', 10], ['s-c', 'firstboot', 2]] as [$name, $type, $priority])
            Script::create(['name' => $name, 'script_type' => $type, 'bg' => false, 'priority' => $priority, 'script' => 'true']);
        $page = Livewire::test(Scripts::class)->assertSeeInOrder(['s-a', 's-b', 's-c']);

        $page->call('sortBy', 'type')->assertSeeInOrder(['s-c', 's-a', 's-b']);   // firstboot, postinstall, preinstall
        $page->call('sortBy', 'priority')->assertSeeInOrder(['s-c', 's-a', 's-b']);   // 2, 9, 10: not "10" before "2"
        $page->call('sortBy', 'priority')->assertSeeInOrder(['s-b', 's-a', 's-c']);
    }

    public function test_labels_sort_by_type_and_by_host_with_the_commands_last()
    {
        Label::create(['name' => 'l-a', 'printer_type' => 'ftp', 'ftp_hostname' => 'zeta', 'ftp_username' => 'u', 'ftp_password' => 'p', 'file_extension' => 'txt', 'template' => 't']);
        Label::create(['name' => 'l-b', 'printer_type' => 'command', 'print_command' => 'lp', 'file_extension' => 'txt', 'template' => 't']);
        Label::create(['name' => 'l-c', 'printer_type' => 'ftp', 'ftp_hostname' => 'alpha', 'ftp_username' => 'u', 'ftp_password' => 'p', 'file_extension' => 'txt', 'template' => 't']);
        $page = Livewire::test(Labels::class)->assertSeeInOrder(['l-a', 'l-b', 'l-c']);

        $page->call('sortBy', 'host')->assertSeeInOrder(['l-c', 'l-a', 'l-b']);
        $page->call('sortBy', 'host')->assertSeeInOrder(['l-a', 'l-c', 'l-b']);
        $page->call('sortBy', 'type')->assertSeeInOrder(['l-b', 'l-a', 'l-c']);
    }

    protected function report(string $mac, string $serial, string $when, string $context): UsbDiagnosticReport
    {
        $report = \App\Support\ModuleDiagnostics::parse("CMPROVISION_DIAGNOSTICS_V1
context=".$context."
serial=".$serial."
target=/dev/mmcblk0
block=missing
controller=present
throttled=0x0
");
        return UsbDiagnosticReport::create(['serial' => $serial, 'boot_id' => 'boot', 'event_id' => 'event-'.$serial, 'mac' => $mac, 'usb_path' => '1-1', 'report' => $report, 'received_at' => Carbon::parse($when)]);
    }

    public function test_usb_reports_go_latest_first_and_the_open_one_stays_the_latest_whatever_the_order()
    {
        $old = $this->report('e4:5f:01:00:00:01', '10000000aaaa0001', '2026-10-20 10:00:00', 'manual');
        $new = $this->report('e4:5f:01:00:00:02', '10000000aaaa0002', '2026-10-22 10:00:00', 'discovery');
        $mid = $this->report('e4:5f:01:00:00:03', '10000000aaaa0003', '2026-10-21 10:00:00', 'failure');
        $page = Livewire::test(UsbDiagnostics::class)->assertSeeInOrder(['10000000aaaa0002', '10000000aaaa0003', '10000000aaaa0001']);

        $page->call('sortBy', 'received')->assertSet('dir', 'desc')->assertSeeInOrder(['10000000aaaa0002', '10000000aaaa0003', '10000000aaaa0001']);
        $page->call('sortBy', 'received')->assertSet('dir', 'asc')->assertSeeInOrder(['10000000aaaa0001', '10000000aaaa0003', '10000000aaaa0002'])
            ->assertViewHas('row', fn ($row) => $row->id === $new->id);
        $page->call('sortBy', 'serial')->assertSeeInOrder(['10000000aaaa0001', '10000000aaaa0002', '10000000aaaa0003']);
        $page->call('sortBy', 'context');
        preg_match_all('~<td>(discovery|failure|manual)</td>~', $page->html(), $contexts);   // the words are in the text of the page too
        $this->assertSame(['discovery', 'failure', 'manual'], $contexts[1]);
        $this->assertNotNull($old->id.$mid->id);
    }

    public function test_every_sortable_header_announces_its_state_and_the_address_may_choose()
    {
        $this->image('a-image.img.gz', 100);
        Livewire::withQueryParams(['sort' => 'size'])->test(Images::class)->assertSeeHtml('aria-sort="descending"');
        Livewire::withQueryParams(['sort' => 'nope', 'dir' => 'desc'])->test(Images::class)->assertDontSeeHtml('aria-sort="descending"')->assertSeeHtml('aria-sort="none"');
    }
}
