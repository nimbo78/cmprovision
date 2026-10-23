<?php

namespace App\Http\Livewire;

use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use App\Http\Livewire\Concerns\SortsColumns;
use App\Support\ColumnSort;
use Livewire\Component;
use App\Models\Image;
use App\Models\Label;
use App\Models\Package;
use App\Models\Project;
use App\Models\Script;
use App\Models\Setting;
use App\Models\Firmware;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use App\Jobs\ComputeSHA256;
use App\Services\EepromImage;
use App\Services\FirmwareNotes;
use App\Services\ProjectActivator;

#[Title('Projects')]
class Projects extends Component
{
    use SortsColumns;
    public $isOpen = false;
    public $active = true;
    public $projects, $activeProject;
    public $projectid, $name, $device, $storage, $image_id, $label_id, $label_moment, $selectedScripts, $offerSettingsReset;
    public $firmware, $eeprom_settings, $verify, $os_check, $selectedPackages, $split_image;
    public $reprovision = false;
    /** the firmware the project had when its form was opened: the choice is shown against it */
    #[Locked]
    public $savedFirmware = '';
    public $images, $labels, $scripts, $packages;

    protected function rules()
    {
        return [
            'name' => 'required|max:255',
            'device' => 'required|in:cm4',
            'storage' => 'required|max:255',
            'image_id' => ['nullable', 'numeric', function ($attribute, $value, $fail) {
                $image = $value ? Image::find($value) : null;
                $own = $this->projectid ? Project::find($this->projectid)?->image_id : null;
                if ($image && !$image->isReady() && (int) $value !== (int) $own)
                    $fail('This image is not ready yet: it is still being fetched, repacked or checked, or it failed.');
            }],
            'label_id' => 'nullable|numeric',
            // a file of the firmware store only: the name comes from the browser
            'firmware' => ['nullable', 'max:255', Rule::in($this->firmwarePaths())],
            'eeprom_settings' => 'nullable|max:2024',
            'label_moment' => 'required|in:never,preinstall,postinstall,firstboot',
            'selectedScripts' => 'array',
            'selectedPackages' => 'array',
            'verify' => 'required|boolean',
            'os_check' => 'required|boolean',
            'split_image' => 'required|boolean',
            'reprovision' => 'required|boolean',
        ];
    }

    /* The columns that sort the table of projects by a click on their header; with none chosen the order is the name */
    protected function columns(): array
    {
        return [
            'name' => ['first' => 'asc', 'value' => fn (Project $project) => (string) $project->name],
            'image' => ['first' => 'asc', 'value' => fn (Project $project) => $project->image?->filename],
        ];
    }

    public function render()
    {
        [$sort, $dir] = $this->sorting();
        $this->projects = ColumnSort::apply(Project::with('image')->orderBy('name')->get(), $this->columns(), $sort, $dir,
            fn ($a, $b) => strnatcasecmp((string) $a->name, (string) $b->name) ?: $a->id <=> $b->id);
        $this->activeProject = Project::getActiveId();
        // the images a project can take: the ready ones and the project's own (an image still being fetched cannot be written)
        $this->images = Image::where(function ($query) {
            $query->whereNull('download_state')->whereNull('download_error');
            if ($this->image_id)
                $query->orWhere('id', $this->image_id);
        })->orderBy('filename')->orderBy('id')->get();
        $this->labels = Label::orderBy('name')->get();
        $this->scripts = Script::orderBy('name')->get();
        $this->packages = Package::orderBy('deb_package')->orderBy('id')->get();
        $firmwareChannels = [];
        foreach (Firmware::channels() as $channel)
        {
            $firmwareChannels[$channel] = Firmware::allOfChannel($channel);
        }

        if ($this->isOpen && $this->label_moment != 'never' && !$this->label_id && count($this->labels))
            $this->label_id = $this->labels[0]->id;

        // view data, not a property: Livewire 3 does not serialize Firmware objects
        return view('livewire.projects', ['firmwareChannels' => $firmwareChannels, 'firmwareChanges' => $this->firmwareChanges(), 'sortKey' => $sort, 'sortDir' => $dir]);
    }

    /** what the chosen firmware changes, against the one the project has now when it differs; null without notes for it */
    protected function firmwareChanges(): ?array
    {
        $notes = new FirmwareNotes;
        $to = $this->firmware ? FirmwareNotes::dateOf(basename($this->firmware)) : null;
        if ($to === null || $notes->latestDate() === null)
            return null;

        $from = $this->savedFirmware ? FirmwareNotes::dateOf(basename($this->savedFirmware)) : null;
        if ($this->savedFirmware === $this->firmware)
            $from = $to;
        if ($from !== null && $from < $to)
            return ['heading' => "Changes since $from (the project's current image) up to $to:", 'entries' => $notes->between($from, $to)];
        if ($from !== null && $from > $to)
            return ['heading' => "Older than the current image ($from). The current image has these changes that this one lacks:", 'entries' => $notes->between($to, $from)];

        $heading = $from === null ? "What this image changes ($to):" : "Same image as now ($to). What it changes:";

        return ['heading' => $heading, 'entries' => $notes->forFile(basename($this->firmware))['changes']];
    }

    /* Called when EEPROM firmware selection changes */
    public function updatingFirmware($newvalue)
    {
        if (!$newvalue)
            return;

        $oldDefaultSettings = $this->firmware ? $this->getEepromSettingsFromFirmwareFile($this->firmware) : '';
        $newDefaultSettings = $this->getEepromSettingsFromFirmwareFile($newvalue);
        $currentSettings = str_replace("\r", "", $this->eeprom_settings);

        if ($currentSettings
            && $currentSettings != $oldDefaultSettings
            && $currentSettings != $newDefaultSettings)
        {
            /* Do not overwrite user's custom settings by default
               do offer user option to 'reset settings' */
            $this->offerSettingsReset = true;
        }
        else
        {
            $this->eeprom_settings = $newDefaultSettings;
            $this->offerSettingsReset = false;
        }
    }

    public function resetEEPROMsettings()
    {
        $this->eeprom_settings = $this->getEepromSettingsFromFirmwareFile($this->firmware);
        $this->offerSettingsReset = false;
    }

    public function openModal()
    {
        $this->resetErrorBag();
        $this->resetValidation();        
        $this->isOpen = true;
    }

    public function closeModal()
    {
        $this->isOpen = false;
    }
    
    public function delete($id)
    {
        Project::destroy($id);
        session()->flash('message', 'Project deleted.');
    }

    public function create()
    {
        $this->name = $this->projectid = $this->label_id = '';
        $this->device = 'cm4';
        $this->storage = "/dev/mmcblk0";
        $this->selectedScripts = [];
        $this->label_moment = 'never';
        $this->image_id = Image::ready()->max('id');
        $this->active = true;
        $this->verify = false;
        $this->os_check = false;
        $this->split_image = false;
        $this->reprovision = false;
        $this->selectedPackages = [];
        $this->eeprom_settings = '';
        $this->firmware = $this->savedFirmware = '';
        $this->offerSettingsReset = false;

        $this->openModal();
    }

    public function edit($id)
    {
        $p = Project::findOrFail($id);
        $this->projectid = $id;
        $this->name = $p->name;
        $this->device = $p->device;
        $this->storage = $p->storage;
        $this->label_moment = $p->label_moment;
        $this->image_id = $p->image_id;
        $this->label_id = $p->label_id;
        $this->verify = $p->verify;
        $this->os_check = $p->os_check;
        $this->split_image = $p->split_image;
        $this->reprovision = $p->reprovision;
        $this->selectedPackages = $p->packages->map(function ($package) { return strval($package->id); })->all();
        $this->active = $p->isActive();
        $this->selectedScripts = [];
        foreach ($p->scripts as $script)
        {
            $this->selectedScripts[] = strval($script->id);
        }
        $this->firmware = $this->savedFirmware = (string) $p->eeprom_firmware;
        $this->eeprom_settings = $p->eeprom_settings;
        $this->offerSettingsReset = false;
        $this->openModal();
    }

    /* Unticking the OS check drops what only it uses: its label moment, its packages and the first-boot scripts */
    public function updatedOsCheck($value)
    {
        if ($value)
            return;
        if ($this->label_moment === 'firstboot')
            $this->label_moment = 'postinstall';
        $this->selectedPackages = [];
        $firstBoot = Script::where('script_type', 'firstboot')->pluck('id')->map(function ($id) { return strval($id); })->all();
        $this->selectedScripts = array_values(array_diff($this->selectedScripts ?: [], $firstBoot));
    }

    public function store()
    {
        $this->validate();

        // what runs in the new system belongs to the OS check
        if (!$this->os_check)
        {
            $errors = [];
            if ($this->label_moment === 'firstboot')
                $errors['label_moment'] = 'Printing after the OS check needs the OS check.';
            if ($this->selectedPackages)
                $errors['selectedPackages'] = 'Packages are installed by the OS check: turn it on or clear them.';
            if (Script::whereIn('id', $this->selectedScripts ?: [])->where('script_type', 'firstboot')->exists())
                $errors['selectedScripts'] = 'First-boot scripts run in the OS check: turn it on or clear them.';
            foreach ($errors as $field => $message)
                $this->addError($field, $message);
            if ($errors)
                return;
        }

        $project = Project::updateOrCreate(['id' => $this->projectid], [
            'name' => $this->name,
            'device' => $this->device,
            'storage' => $this->storage,
            'label_moment' => $this->label_moment,
            'image_id' => $this->image_id ? $this->image_id : null,
            'label_id' => $this->label_id ? $this->label_id : null,
            'eeprom_firmware' => $this->firmware ? $this->firmware : null,
            'eeprom_settings' => $this->eeprom_settings ? str_replace("\r", "", $this->eeprom_settings) : null,
            'verify' => $this->verify,
            'os_check' => (bool) $this->os_check,
            'split_image' => (bool) $this->split_image,
            'reprovision' => (bool) $this->reprovision,
        ]);
        $project->scripts()->sync($this->selectedScripts);
        $project->packages()->sync($this->selectedPackages ?: []);

        if ($this->active)
        {
            $this->setActive($project->id);
        }

        if ($this->verify && $this->image_id && $project->image->isReady() && !$project->image->uncompressed_sha256)
        {
            /* Queue SHA256 calculation job */
            ComputeSHA256::dispatch($project->image);
        }

        if ($this->split_image && $project->image && $project->image->isReady())
        {
            /* the pieces copy (2.6.1): made in the background, until then the project writes the original */
            $project->image->queueSplit();
        }

        $this->closeModal();
    }

    public function cancel()
    {
        $this->closeModal();
    }

    public function setActive($id)
    {
        $p = Project::findOrFail($id);
        $this->activeProject = $id;

        try
        {
            (new ProjectActivator)->activate($p);
        }
        catch (\RuntimeException $e)
        {
            session()->flash('message', $e->getMessage());
        }
    }

    /** the settings embedded in an image of the firmware store; '' for anything else */
    protected function getEepromSettingsFromFirmwareFile($path)
    {
        if (!in_array($path, $this->firmwarePaths(), true))
            return '';
        $data = @file_get_contents(Firmware::basedir().'/'.$path);
        return $data === false ? '' : (string) EepromImage::getConfig($data);
    }

    /** "<channel>/pieeprom-....bin" of every image in the store */
    protected function firmwarePaths()
    {
        return array_map(function (Firmware $f) { return $f->path; }, Firmware::all());
    }
}
