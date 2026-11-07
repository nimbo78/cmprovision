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
use Illuminate\Validation\ValidationException;
use App\Jobs\ComputeSHA256;
use App\Services\EepromImage;
use App\Services\FirmwareNotes;
use App\Services\ProjectActivator;
use App\Services\Configuration\Definitions;

#[Title('Projects')]
class Projects extends Component
{
    use SortsColumns;
    use \App\Http\Livewire\Concerns\CreatesWithWizard;
    use \Laravel\Jetstream\ConfirmsPasswords;
    public $stationEnabled = false, $station = [];
    #[Locked]
    public $savedStationSetup = false;
    public $isOpen = false;
    public $active = true;
    public $projects, $activeProject;
    public $projectid, $name, $device, $storage, $image_id, $label_id, $label_moment, $selectedScripts, $offerSettingsReset;
    public $firmware, $eeprom_settings, $verify, $os_check, $selectedPackages, $split_image;
    public $reprovision = false;
    /** for how many boots the agent's mark names the provisioner over LLDP (ModuleMark); 0, none */
    public $mark_boots = 0;
    /** the firmware the project had when its form was opened: the choice is shown against it */
    #[Locked]
    public $savedFirmware = '';
    public $images, $labels, $scripts, $packages;

    protected function rules()
    {
        return Definitions::projectRules($this->projectid ? Project::find($this->projectid) : null);
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
        $this->endWizard();
        $this->isOpen = false;
        $this->station = [];
    }
    
    public function delete($id)
    {
        Project::destroy($id);
        session()->flash('message', 'Project deleted.');
    }

    public function create()
    {
        $this->endWizard();
        $this->stationEnabled = $this->savedStationSetup = false;
        $this->station = \App\Support\StationSetupInput::form();
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
        $this->mark_boots = 0;
        $this->selectedPackages = [];
        $this->eeprom_settings = '';
        $this->firmware = $this->savedFirmware = '';
        $this->offerSettingsReset = false;

        $this->openModal();
    }

    public function edit($id)
    {
        $this->endWizard();
        $p = Project::findOrFail($id);
        $this->stationEnabled = $this->savedStationSetup = $p->station_setup !== null;
        $this->station = \App\Support\StationSetupInput::form($p->station_setup);
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
        $this->mark_boots = (int) $p->mark_boots;
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

    public function updatedStation($value, $key = null)
    {
        if ($key !== 'profile' || $value !== 'raspios') return;
        $this->station['wifi'] = false;
        $this->station['ssh'] = true;
        if (($this->station['hostname'] ?? '') === 'cmprovision-{serial}') $this->station['hostname'] = '';
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
        $this->ensureWizardSummary();
        if ($this->wizardMode) {
            $this->projectid = '';
            $this->validateWizardReferences();
        }
        $this->validate();
        $existing = $this->projectid ? Project::find($this->projectid) : null;
        if ($this->wizardMode && $this->stationEnabled) {
            $existing = new Project;
            $existing->station_setup = $this->wizardData()['station_setup'] ?? null;
        }
        if ($this->stationEnabled || $existing?->station_setup !== null) {
            $this->ensurePasswordIsConfirmed();
        }
        try {
            $definition = Definitions::project($this->projectInput(), $existing);
        } catch (ValidationException $error) {
            $this->station['password'] = $this->station['wifi_password'] = '';
            throw $error;
        }

        $project = Project::updateOrCreate(['id' => $this->projectid], $definition['attributes']);
        $project->station_setup = $definition['station_setup'];
        $project->save();
        $scripts = $definition['script_ids'];
        if ($this->wizardMode) {
            $scripts = [];
            foreach ($definition['script_ids'] as $position => $id) $scripts[$id] = ['position' => $position];
        }
        $project->scripts()->sync($scripts);
        $project->packages()->sync($definition['package_ids']);

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

    public function createWizard(?int $copyId = null): void
    {
        if ($copyId === null) $this->create();
        else $this->edit($copyId);
        $source = $copyId === null ? null : Project::findOrFail($copyId);
        $this->beginWizard();
        $this->projectid = '';
        $this->active = false;
        if ($source === null) $this->verify = $this->os_check = $this->split_image = true;
        else {
            $base = mb_substr($source->name, 0, 220).' (copy)';
            $name = $base;
            for ($i = 2; Project::where('name', $name)->exists(); $i++) $name = $base.' '.$i;
            $this->name = $name;
            $this->selectedScripts = $source->orderedScripts()->pluck('scripts.id')->map(fn ($id) => (string) $id)->all();
            if ($source->station_setup !== null) $this->keepWizardData(['station_setup' => $source->station_setup]);
        }
    }

    public function wizardSteps(): array
    {
        $steps = [1 => 'Purpose and name', 2 => 'Image and storage', 3 => 'Checks and bootloader',
            4 => 'Scripts and packages', 5 => 'Label'];
        if ($this->stationEnabled) $steps[6] = ($this->station['profile'] ?? 'station') === 'raspios' ? 'OS SSH access' : 'Station access and Wi-Fi';
        $steps[7] = 'Review and save';
        return $steps;
    }

    protected function validateWizardStep(#[\SensitiveParameter] array $secrets): void
    {
        $fields = match ($this->wizardStep) {
            1 => ['name', 'stationEnabled'], 2 => ['image_id', 'storage'],
            3 => ['verify', 'os_check', 'split_image', 'reprovision', 'mark_boots', 'firmware', 'eeprom_settings'],
            4 => ['selectedScripts', 'selectedPackages'], 5 => ['label_id', 'label_moment'], default => [],
        };
        if ($fields) $this->validate(array_intersect_key(Definitions::projectRules(), array_flip($fields)));
        $this->validateWizardReferences();
        if (in_array($this->wizardStep, [4, 5], true)) Definitions::project($this->projectInput(false));
        if ($this->wizardStep === 3 && $this->stationEnabled && !$this->verify) {
            throw ValidationException::withMessages(['verify' => 'Station setup requires SHA-256 verification.']);
        }
        if ($this->wizardStep === 6) {
            if (array_diff(array_keys($secrets), ['password', 'wifi_password'])) throw ValidationException::withMessages(['wizard' => 'Unsupported credential field.']);
            $input = $this->station;
            foreach ($secrets as $key => $value) $input[$key] = $value;
            try { $setup = \App\Support\StationSetupInput::normalize($input, $this->wizardData()['station_setup'] ?? null); }
            catch (ValidationException $error) {
                throw ValidationException::withMessages(collect($error->errors())->mapWithKeys(fn ($value, $field) => ['station.'.$field => $value])->all());
            }
            $this->keepWizardData(['station_setup' => $setup]);
            $this->dispatch('wizard-credentials-stored');
            $this->savedStationSetup = true;
            $this->station['password'] = $this->station['wifi_password'] = '';
        }
    }

    protected function projectInput(bool $station = true): array
    {
        $input = [];
        foreach (['name', 'device', 'storage', 'label_moment', 'image_id', 'label_id', 'firmware', 'eeprom_settings',
            'verify', 'os_check', 'split_image', 'reprovision', 'mark_boots', 'stationEnabled', 'station'] as $key) $input[$key] = $this->$key;
        $input['selectedScripts'] = $this->selectedScripts ?: [];
        $input['selectedPackages'] = $this->selectedPackages ?: [];
        if (!$station) $input['stationEnabled'] = false;
        return $input;
    }

    protected function validateWizardReferences(): void
    {
        $rules = [];
        if (in_array($this->wizardStep, [1, 7], true)) $rules['name'] = 'required|string|max:255|unique:projects,name';
        if (in_array($this->wizardStep, [2, 7], true)) {
            $rules['image_id'] = ($this->stationEnabled ? 'required' : 'nullable').'|integer|exists:images,id';
            if ($this->stationEnabled) $rules['storage'] = 'required|regex:#^/dev/mmcblk[0-9]+$#D';
        }
        if (in_array($this->wizardStep, [4, 7], true)) {
            $rules['selectedScripts.*'] = 'integer|distinct|exists:scripts,id';
            $rules['selectedPackages.*'] = 'integer|distinct|exists:packages,id';
        }
        if (in_array($this->wizardStep, [5, 7], true)) $rules['label_id'] = ($this->label_moment === 'never' ? 'nullable' : 'required').'|integer|exists:labels,id';
        if ($rules) $this->validate($rules);
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
