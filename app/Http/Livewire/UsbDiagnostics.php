<?php

namespace App\Http\Livewire;

use App\Models\Cm;
use App\Models\UsbDiagnosticReport;
use App\Support\ModuleDiagnostics;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use App\Http\Livewire\Concerns\SortsColumns;
use App\Support\ColumnSort;
use Livewire\Component;

#[Title('USB diagnostics')]
class UsbDiagnostics extends Component
{
    use SortsColumns;

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

    /* The columns that sort the list of the latest 50 reports by a click on their header; with none chosen the latest is first */
    protected function columns(): array
    {
        return [
            'mac' => ['first' => 'asc', 'value' => fn (UsbDiagnosticReport $report) => $report->mac ?: null],
            'serial' => ['first' => 'asc', 'value' => fn (UsbDiagnosticReport $report) => (string) $report->serial],
            'received' => ['first' => 'desc', 'value' => fn (UsbDiagnosticReport $report) => $report->received_at->getTimestamp()],
            'context' => ['first' => 'asc', 'value' => fn (UsbDiagnosticReport $report) => ($report->report['context'] ?? '') !== '' ? (string) $report->report['context'] : null],
        ];
    }

    public function render()
    {
        $rows = UsbDiagnosticReport::orderByDesc('received_at')->orderByDesc('id')->limit(50)->get();
        // the report that opens is the latest, whatever the order of the list
        $latest = $rows->first();
        [$sort, $dir] = $this->sorting();
        $rows = ColumnSort::apply($rows, $this->columns(), $sort, $dir, fn ($a, $b) => [$b->received_at->getTimestamp(), $b->id] <=> [$a->received_at->getTimestamp(), $a->id]);
        $row = $this->selected ? UsbDiagnosticReport::find($this->selected) : $latest;
        return view('livewire.usb-diagnostics', ['rows' => $rows, 'row' => $row, 'sortKey' => $sort, 'sortDir' => $dir,
            'cm' => $row ? Cm::where('serial', $row->serial)->first() : null,
            'diagnosis' => $row ? ModuleDiagnostics::summary($row->report) : null]);
    }
}
