<?php

namespace App\Http\Livewire;

use Livewire\Attributes\Title;
use Livewire\Component;
use App\Models\Image;
use App\Models\Label;
use App\Models\Project;
use App\Models\Script;
use App\Models\Setting;
use App\Models\Firmware;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use App\Jobs\ComputeSHA256;
use App\Services\EepromImage;
use App\Services\ProjectActivator;

#[Title('Projects')]
class Projects extends Component
{
    public $isOpen = false;
    public $active = true;
    public $projects, $activeProject;
    public $projectid, $name, $device, $storage, $image_id, $label_id, $label_moment, $selectedScripts, $offerSettingsReset;
    public $firmware, $eeprom_settings, $verify;
    public $images, $labels, $scripts;

    protected function rules()
    {
        return [
            'name' => 'required|max:255',
            'device' => 'required|in:cm4',
            'storage' => 'required|max:255',
            'image_id' => 'nullable|numeric',
            'label_id' => 'nullable|numeric',
            // a file of the firmware store only: the name comes from the browser
            'firmware' => ['nullable', 'max:255', Rule::in($this->firmwarePaths())],
            'eeprom_settings' => 'nullable|max:2024',
            'label_moment' => 'required|in:never,preinstall,postinstall',
            'selectedScripts' => 'array',
            'verify' => 'required|boolean',
        ];
    }

    public function render()
    {
        $this->projects = Project::orderBy('name')->get();
        $this->activeProject = Project::getActiveId();
        $this->images = Image::orderBy('filename')->orderBy('id')->get();
        $this->labels = Label::orderBy('name')->get();
        $this->scripts = Script::orderBy('name')->get();
        $firmwareChannels = [];
        foreach (Firmware::channels() as $channel)
        {
            $firmwareChannels[$channel] = Firmware::allOfChannel($channel);
        }

        if ($this->isOpen && $this->label_moment != 'never' && !$this->label_id && count($this->labels))
            $this->label_id = $this->labels[0]->id;

        // view data, not a property: Livewire 3 does not serialize Firmware objects
        return view('livewire.projects', ['firmwareChannels' => $firmwareChannels]);
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
        $this->image_id = Image::max('id');
        $this->active = true;
        $this->verify = false;
        $this->eeprom_settings = '';
        $this->firmware = '';
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
        $this->active = $p->isActive();
        $this->selectedScripts = [];
        foreach ($p->scripts as $script)
        {
            $this->selectedScripts[] = strval($script->id);
        }
        $this->firmware = $p->eeprom_firmware;
        $this->eeprom_settings = $p->eeprom_settings;
        $this->offerSettingsReset = false;
        $this->openModal();
    }

    public function store()
    {
        $this->validate();

        $project = Project::updateOrCreate(['id' => $this->projectid], [
            'name' => $this->name,
            'device' => $this->device,
            'storage' => $this->storage,
            'label_moment' => $this->label_moment,
            'image_id' => $this->image_id ? $this->image_id : null,
            'label_id' => $this->label_id ? $this->label_id : null,
            'eeprom_firmware' => $this->firmware ? $this->firmware : null,
            'eeprom_settings' => $this->eeprom_settings ? str_replace("\r", "", $this->eeprom_settings) : null,
            'verify' => $this->verify
        ]);
        $project->scripts()->sync($this->selectedScripts);

        if ($this->active)
        {
            $this->setActive($project->id);
        }

        if ($this->verify && $this->image_id && !$project->image->uncompressed_sha256)
        {
            /* Queue SHA256 calculation job */
            ComputeSHA256::dispatch($project->image);
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
