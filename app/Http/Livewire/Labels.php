<?php

namespace App\Http\Livewire;

use Livewire\Attributes\Title;
use App\Http\Livewire\Concerns\SortsColumns;
use App\Support\ColumnSort;
use Livewire\Component;
use App\Models\Label;
use App\Services\Configuration\Definitions;

#[Title('Labels')]
class Labels extends Component
{
    use SortsColumns;
    use \App\Http\Livewire\Concerns\CreatesWithWizard;
    public $isOpen = false;
    public $labels;
    public $labelid, $name, $printer_type, $print_command;
    public $ftp_hostname, $ftp_username, $ftp_password, $file_extension, $template;
    public $dummypassword = "****************";

    protected function rules(): array
    {
        return Definitions::labelRules();
    }

    /* The columns that sort the table of labels by a click on their header; with none chosen the order is the name */
    protected function columns(): array
    {
        return [
            'name' => ['first' => 'asc', 'value' => fn (Label $label) => (string) $label->name],
            'type' => ['first' => 'asc', 'value' => fn (Label $label) => (string) $label->printer_type],
            'host' => ['first' => 'asc', 'value' => fn (Label $label) => $label->printer_type === 'ftp' && trim((string) $label->ftp_hostname) !== '' ? $label->ftp_hostname : null],
        ];
    }

    public function render()
    {
        [$sort, $dir] = $this->sorting();
        $this->labels = ColumnSort::apply(Label::orderBy('name')->get(), $this->columns(), $sort, $dir,
            fn ($a, $b) => strnatcasecmp((string) $a->name, (string) $b->name) ?: $a->id <=> $b->id);
        return view('livewire.labels', ['sortKey' => $sort, 'sortDir' => $dir]);
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
        $this->ftp_password = '';
        $this->isOpen = false;
    }
    
    public function delete($id)
    {
        Label::destroy($id);
        session()->flash('message', 'Label deleted.');
    }

    public function create()
    {
        $this->endWizard();
        $this->name = $this->labelid = '';
        $this->printer_type = 'command';
        $this->ftp_hostname = $this->ftp_username = $this->ftp_password = '';
        $this->template = '';
        $this->file_extension = 'jscript';
        $this->print_command = '/usr/bin/lpr -o raw $file 2>&1';
        $this->openModal();
    }

    public function edit($id)
    {
        $this->endWizard();
        $l = Label::findOrFail($id);
        $this->labelid = $id;
        $this->name = $l->name;
        $this->printer_type = $l->printer_type;
        $this->ftp_hostname = $l->ftp_hostname;
        $this->ftp_username = $l->ftp_username;
        $this->ftp_password = empty($l->ftp_password) ? "" : $this->dummypassword;
        $this->template = $l->template;
        $this->file_extension = $l->file_extension;
        $this->print_command = $l->print_command;
        $this->openModal();
    }

    public function store()
    {
        $this->ensureWizardSummary();
        if ($this->wizardMode) {
            $data = Definitions::label($this->labelWizardValues());
            Label::create($data);
            $this->closeModal();
            return;
        }
        $this->validate();

        $data = [
            'name' => $this->name,
            'printer_type' => $this->printer_type,
            'ftp_hostname' => $this->ftp_hostname,
            'ftp_username' => $this->ftp_username,
            'template' => $this->template,
            'file_extension' => $this->file_extension,
            'print_command' => $this->print_command
        ];
        if ($this->ftp_password != $this->dummypassword)
        {
            $data['ftp_password'] = $this->ftp_password;
        }

        $existing = $this->labelid ? Label::findOrFail($this->labelid) : null;
        $data = Definitions::label($data, $existing);
        if ($this->ftp_password == $this->dummypassword) {
            unset($data['ftp_password']);
        }

        Label::updateOrCreate(['id' => $this->labelid], $data);

        $this->closeModal();
    }

    public function cancel()
    {
        $this->closeModal();
    }

    public function printTestLabel()
    {
        $wizard = null;
        if ($this->wizardMode) {
            if ($this->wizardStep < 3) throw \Illuminate\Validation\ValidationException::withMessages(['wizard' => 'Complete the printer and template steps before printing.']);
            $wizard = Definitions::label($this->labelWizardValues());
        }
        $serial = '1000000012345678';
        $label = $this->previewLabel();
        $tmpfile = tempnam(sys_get_temp_dir(), "label-");
        $ftp_password = $wizard === null ? $this->ftp_password : $wizard['ftp_password'];
        if ($wizard === null && $ftp_password == $this->dummypassword)
        {
            $l = Label::findOrFail($this->labelid);
            $ftp_password = $l->ftp_password;
        }
        $said = '';

        try
        {
            if (!@file_put_contents($tmpfile, $label))
                throw new \Exception("Error creating temporary file for label '$tmpfile'");

            if ($this->printer_type == 'ftp')
            {
                $ftp = @ftp_connect($this->ftp_hostname);
                if (!$ftp)
                    throw new \Exception("Error connecting to printer's FTP server ".$this->ftp_hostname);
                if (!@ftp_login($ftp, $this->ftp_username, $ftp_password))
                    throw new \Exception("Error logging in to printer's FTP server. Check username and password");
                @ftp_pasv($ftp, true);
                if (!@ftp_put($ftp, "label-$serial.".$this->file_extension, $tmpfile))
                    throw new \Exception("Error uploading file to printer's FTP server");
                @ftp_close($ftp);
            }
            else if ($this->printer_type == 'command')
            {
                $cmd = str_replace('$file', escapeshellarg($tmpfile), $this->print_command);
                $output = $retcode = null;
                // stderr too, as ScriptExecuteController::printLabel() does
                if (@exec('{ '.$cmd."\n} 2>&1", $output, $retcode) === false)
                    throw new \Exception("Error executing '$cmd'");
                if ($retcode)
                    throw new \Exception("Executing '$cmd' returned exit code $retcode. Program output:\n".implode("\n", $output));
                $said = trim(implode("\n", $output));
            }

            session()->flash('message', 'Printed test label'.($said !== '' ? '. Command output: '.$said : ''));
        }
        catch (\Exception $e)
        {
            session()->flash('message', $e->getMessage() );
        }
        @unlink($tmpfile);
    }    

    public function createWizard(?int $copyId = null): void
    {
        if ($copyId === null) $this->create();
        else $this->edit($copyId);
        $source = $copyId === null ? null : Label::findOrFail($copyId);
        $this->beginWizard();
        $this->labelid = '';
        $this->ftp_password = '';
        if ($source === null) $this->template = '$serial'."\n".'$mac'."\n".'$hostname';
        else {
            $base = mb_substr($source->name, 0, 220).' (copy)';
            $name = $base;
            for ($i = 2; Label::where('name', $name)->exists(); $i++) $name = $base.' '.$i;
            $this->name = $name;
            if ($source->printer_type === 'ftp') $this->keepWizardData(['ftp' => [
                'hostname' => $source->ftp_hostname, 'username' => $source->ftp_username, 'password' => $source->ftp_password,
            ]]);
        }
    }

    public function wizardSteps(): array
    {
        return [1 => 'Name and output', 2 => 'Printer connection', 3 => 'Template and preview', 4 => 'Review and save'];
    }

    protected function validateWizardStep(#[\SensitiveParameter] array $secrets): void
    {
        if ($this->wizardStep === 1) $this->validate(array_intersect_key(Definitions::labelRules(), array_flip(['name', 'printer_type', 'file_extension'])));
        if ($this->wizardStep === 2) {
            if (array_diff(array_keys($secrets), ['ftp_password'])) throw \Illuminate\Validation\ValidationException::withMessages(['wizard' => 'Unsupported credential field.']);
            \Illuminate\Support\Facades\Validator::make($secrets, ['ftp_password' => 'sometimes|string|max:255'])->validate();
            $values = $this->labelWizardValues($secrets['ftp_password'] ?? null);
            \Illuminate\Support\Facades\Validator::make($values, array_intersect_key(Definitions::labelRules(),
                array_flip(['printer_type', 'ftp_hostname', 'ftp_username', 'ftp_password', 'print_command'])))->validate();
            if ($this->printer_type === 'ftp') $this->keepWizardData(['ftp' => [
                'hostname' => $this->ftp_hostname, 'username' => $this->ftp_username, 'password' => $values['ftp_password'],
            ]]);
            $this->dispatch('wizard-credentials-stored');
        }
        if ($this->wizardStep === 3) $this->validate(['template' => 'required|string']);
    }

    protected function labelWizardValues(#[\SensitiveParameter] ?string $password = null): array
    {
        $values = [];
        foreach (['name', 'printer_type', 'ftp_hostname', 'ftp_username', 'print_command', 'template', 'file_extension'] as $key) $values[$key] = $this->$key;
        $saved = $this->wizardData()['ftp'] ?? [];
        if (($password === null || $password === '') && ($saved['hostname'] ?? null) === $this->ftp_hostname
            && ($saved['username'] ?? null) === $this->ftp_username) $password = $saved['password'];
        $values['ftp_password'] = $this->printer_type === 'ftp' ? ($password ?? '') : '';
        return $values;
    }

    public function previewLabel(): string
    {
        return strtr((string) $this->template, [
            '$serial' => '1000000012345678', '$mac' => '11:22:33:44:55:66', '$hostname' => 'wlanpi-a1b',
            '$provisionboard' => 'sw01 Gi0/3', '$switch' => 'sw01', '$port' => 'Gi0/3',
        ]);
    }
}
