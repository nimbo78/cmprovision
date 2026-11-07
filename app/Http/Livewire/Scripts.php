<?php

namespace App\Http\Livewire;

use Livewire\Attributes\Title;
use App\Http\Livewire\Concerns\SortsColumns;
use App\Support\ColumnSort;
use Livewire\Component;
use App\Models\Package;
use App\Models\Script;
use App\Services\Configuration\Definitions;

#[Title('Scripts')]
class Scripts extends Component
{
    use SortsColumns;
    use \App\Http\Livewire\Concerns\CreatesWithWizard;
    public $isOpen = false;
    public $scripts, $packages;
    public $scriptid, $name, $script_type, $bg, $priority, $script;

    protected function rules(): array
    {
        return Definitions::scriptRules();
    }

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
        $this->endWizard();
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
        $this->endWizard();
        $this->name = $this->scriptid = '';
        $this->script_type = 'postinstall';
        $this->bg = false;
        $this->priority = 100;
        $this->script = "#!/bin/sh\nset -e\n\n";
        $this->openModal();
    }

    public function edit($id)
    {
        $this->endWizard();
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
        $this->ensureWizardSummary();
        if ($this->wizardMode) {
            $this->scriptid = '';
            $this->validate(array_replace(Definitions::scriptRules(), ['name' => 'required|string|max:255', 'script' => 'required|string']));
        }
        $data = Definitions::script([
            'name' => $this->name,
            'script_type' => $this->script_type,
            'bg' => $this->bg,
            'priority' => $this->priority,
            'script' => $this->script,
        ]);
        Script::updateOrCreate(['id' => $this->scriptid], $data);

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
        $source = $copyId === null ? null : Script::findOrFail($copyId);
        $this->beginWizard();
        $this->scriptid = '';
        if ($source !== null) {
            $base = mb_substr($source->name, 0, 220).' (copy)';
            $name = $base;
            for ($i = 2; Script::where('name', $name)->exists(); $i++) $name = $base.' '.$i;
            $this->name = $name;
        }
        if ($this->script_type === 'firstboot') $this->bg = false;
    }

    public function wizardSteps(): array
    {
        return [1 => 'Name and purpose', 2 => 'Execution phase', 3 => 'Order and execution',
            4 => 'Script content', 5 => 'Review and save'];
    }

    public function updatedScriptType(): void
    {
        if ($this->wizardMode && $this->script_type === 'firstboot') $this->bg = false;
    }

    protected function validateWizardStep(#[\SensitiveParameter] array $secrets): void
    {
        $fields = match ($this->wizardStep) { 1 => ['name'], 2 => ['script_type'], 3 => ['priority', 'bg'], 4 => ['script'], default => [] };
        $rules = array_replace(Definitions::scriptRules(), ['name' => 'required|string|max:255', 'script' => 'required|string']);
        if ($fields) $this->validate(array_intersect_key($rules, array_flip($fields)));
        if ($this->script_type === 'firstboot') $this->bg = false;
    }
}
