<?php

namespace App\Http\Livewire;

use App\Models\Cm;
use App\Models\UsbDiagnosticReport;
use App\Support\ModuleDiagnostics;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('USB diagnostics')]
class UsbDiagnostics extends Component
{
    #[Locked]
    public $selected = null;

    public function mount($id = null)
    {
        if ($id !== null) $this->selected = UsbDiagnosticReport::findOrFail($id)->id;
    }

    public function select($id)
    {
        $this->selected = UsbDiagnosticReport::findOrFail($id)->id;
    }

    public function download($id)
    {
        $row = UsbDiagnosticReport::findOrFail($id);
        $text = ModuleDiagnostics::format($row->report, $row->serial, $row->received_at->toIso8601String());
        return response()->streamDownload(fn () => print($text), 'usb-diagnostics-'.$row->id.'.txt', ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function render()
    {
        $rows = UsbDiagnosticReport::orderByDesc('received_at')->orderByDesc('id')->limit(50)->get();
        $row = $this->selected ? UsbDiagnosticReport::find($this->selected) : $rows->first();
        return view('livewire.usb-diagnostics', ['rows' => $rows, 'row' => $row,
            'cm' => $row ? Cm::where('serial', $row->serial)->first() : null,
            'diagnosis' => $row ? ModuleDiagnostics::summary($row->report) : null]);
    }
}
