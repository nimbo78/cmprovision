<?php

namespace App\Http\Livewire;

use Livewire\Attributes\Title;
use App\Http\Livewire\Concerns\SortsColumns;
use App\Support\ColumnSort;
use Livewire\Component;
use App\Models\Package;
use App\Models\Script;

#[Title('Scripts')]
class Scripts extends Component
{
    use SortsColumns;
    public $isOpen = false;
    public $scripts, $packages;
    public $scriptid, $name, $script_type, $bg, $priority, $script;

    protected $rules = [
        'name' => 'required|max:255',
        'script_type' => 'required|in:preinstall,postinstall,firstboot',
        'bg' => 'required|boolean',
        'priority' => 'required|numeric',
        'script' => 'required'
    ];

    /* The columns that sort the table of scripts by a click on their header; with none chosen the order is the name */
    protected function columns(): array
    {
        return [
            'name' => ['first' => 'asc', 'value' => fn (Script $script) => (string) $script->name],
            'type' => ['first' => 'asc', 'value' => fn (Script $script) => (string) $script->script_type],
            'priority' => ['first' => 'asc', 'value' => fn (Script $script) => is_numeric($script->priority) ? (int) $script->priority : null],
        ];
    }

    public function render()
    {
        [$sort, $dir] = $this->sorting();
        $this->scripts = ColumnSort::apply(Script::orderBy('name')->get(), $this->columns(), $sort, $dir,
            fn ($a, $b) => strnatcasecmp((string) $a->name, (string) $b->name) ?: $a->id <=> $b->id);
        $this->packages = Package::orderBy('deb_package')->orderBy('id')->get();
        return view('livewire.scripts', ['sortKey' => $sort, 'sortDir' => $dir]);
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
        $projects = Script::findOrFail($id)->projects;
        if (count($projects))
        {
            $plist = '';
            foreach ($projects as $project)
            {
                $plist .= "'".$project->name."' ";
            }
            session()->flash('message', 'Cannot delete. Script is being used by projects: '.$plist);
            return;
        }

        Script::destroy($id);
        session()->flash('message', 'Script deleted.');
    }

    /* A package goes only while no project installs it on the first boot */
    public function deletePackage($id)
    {
        $package = Package::findOrFail($id);
        $projects = $package->projects->map(function ($project) { return "'".$project->name."'"; })->implode(' ');
        if ($projects !== '')
        {
            session()->flash('message', 'Cannot delete: package '.$package->deb_package.' is installed by projects: '.$projects);
            return;
        }
        @unlink($package->path());
        $package->delete();
        session()->flash('message', 'Package '.$package->deb_package.' deleted.');
    }

    public function create()
    {
        $this->name = $this->scriptid = '';
        $this->script_type = 'postinstall';
        $this->bg = false;
        $this->priority = 100;
        $this->script = "#!/bin/sh\nset -e\n\n";
        $this->openModal();
    }

    public function edit($id)
    {
        $s = Script::findOrFail($id);
        $this->scriptid = $id;
        $this->name = $s->name;
        $this->script_type = $s->script_type;
        $this->bg = $s->bg;
        $this->priority = $s->priority;
        $this->script = $s->script;
        $this->openModal();
    }

    public function store()
    {
        $this->validate();

        Script::updateOrCreate(['id' => $this->scriptid], [
            'name' => $this->name,
            'script_type' => $this->script_type,
            // a first-boot script runs in the new system's service, one after the other
            'bg' => $this->script_type === 'firstboot' ? false : $this->bg,
            'priority' => $this->priority,
            'script' => str_replace("\r", "", $this->script)
        ]);

        $this->closeModal();
    }

    public function cancel()
    {
        $this->closeModal();
    }
}
