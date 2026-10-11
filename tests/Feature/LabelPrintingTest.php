<?php

namespace Tests\Feature;

use App\Http\Livewire\Labels;
use App\Models\Cmlog;
use App\Models\Image;
use App\Models\Label;
use App\Models\Project;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A label "printer" may be any command. What it prints goes to the log even when it exits 0:
 * project 78's CSV logger printed "Permission denied" for two years and nobody saw it.
 */
class LabelPrintingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (!is_executable('/bin/sh')) {
            $this->markTestSkipped('the print command runs in a POSIX shell');
        }
    }

    protected function projectPrintingWith($command)
    {
        $label = Label::create(['name' => 'csv', 'printer_type' => 'command', 'print_command' => $command,
                                'template' => '$serial,$mac', 'file_extension' => 'txt']);
        $image = new Image;
        $image->filename = 'wlanpi-os.img.gz';
        $image->filename_extension = 'gz';
        $image->filename_on_server = 'img.gz';
        $image->sha256 = str_repeat('a', 64);
        $image->uncompressed_size = 8 * 1024 * 1024;
        $image->save();
        $project = Project::create(['name' => '78', 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'image_id' => $image->id,
                                    'label_id' => $label->id, 'label_moment' => 'preinstall', 'verify' => false]);
        Setting::updateOrCreate(['key' => 'active_project'], ['value' => $project->id]);
    }

    protected function start($serial)
    {
        $this->get("/scriptexecute?serial=$serial&model=CM4&storagesize=62500000&mac=e4:5f:01:00:00:01")->assertOk();
    }

    public function test_what_the_print_command_says_goes_to_the_log()
    {
        $this->projectPrintingWith('echo "cannot write /home/wlanpi/cmprovision.csv"');

        $this->start('1000000000000e01');

        $this->assertSame(1, Cmlog::where('cm', '1000000000000e01')
            ->where('msg', 'Label command output: cannot write /home/wlanpi/cmprovision.csv')->count());
    }

    public function test_a_silent_print_command_adds_nothing()
    {
        $this->projectPrintingWith('true');

        $this->start('1000000000000e02');

        $this->assertSame(0, Cmlog::where('msg', 'like', 'Label command output%')->count());
    }

    public function test_the_test_label_shows_what_the_command_said()
    {
        Livewire::test(Labels::class)
            ->set('printer_type', 'command')
            ->set('print_command', 'echo hello from the printer')
            ->set('template', '$serial')
            ->set('file_extension', 'txt')
            ->call('printTestLabel')
            ->assertSee('Printed test label. Command output: hello from the printer');
    }
}
