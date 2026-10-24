<?php

namespace App\Http\Livewire;

use App\Http\Livewire\Concerns\SortsColumns;
use App\Models\Cm;
use App\Models\Project;
use App\Services\NetBoxExport;
use App\Support\ColumnSort;
use Livewire\Attributes\Title;
use Livewire\Component;

/* The list of CMs: what runs now first, then by the time they were flashed, the latest on top; by project, searchable by a part of the serial or the MAC */
#[Title('CMs')]
class Cms extends Component
{
    use SortsColumns;

    public $projectId = -1;
    public $search = '';

    /* The columns that sort the list by a click on their header; with none chosen the order is Cm::newestFirst() */
    protected function columns(): array
    {
        $result = ['failed' => 0, 'preinstall' => 1, 'write' => 2, 'verify' => 3, 'postinstall' => 4, 'firstboot' => 5, 'done' => 6];
        return [
            'mac' => ['first' => 'asc', 'value' => fn (Cm $cm) => $cm->mac ?: null],
            'hostname' => ['first' => 'asc', 'value' => fn (Cm $cm) => trim((string) $cm->hostname) !== '' ? $cm->hostname : null],
            'serial' => ['first' => 'asc', 'value' => fn (Cm $cm) => $cm->serial],
            'model' => ['first' => 'asc', 'value' => fn (Cm $cm) => trim((string) $cm->model) !== '' ? $cm->model : null],
            'project' => ['first' => 'asc', 'value' => fn (Cm $cm) => $cm->project?->name],
            'board' => ['first' => 'asc', 'value' => fn (Cm $cm) => trim((string) $cm->provisioning_board) !== '' ? (string) $cm->provisioning_board : null],
            'result' => ['first' => 'asc', 'value' => fn (Cm $cm) => $result[$cm->phase] ?? null],
            'finished' => ['first' => 'desc', 'value' => fn (Cm $cm) => $cm->finishedAt()?->getTimestamp()],
        ];
    }

    protected function cms()
    {
        $query = Cm::with('project');
        if ($this->projectId)
            $query->where('project_id', $this->projectId);
        $term = strtolower(trim((string) $this->search));
        if ($term !== '')
            $query->where(function ($q) use ($term) {
                $q->whereRaw('lower(serial) like ?', ['%'.$term.'%'])->orWhereRaw('lower(mac) like ?', ['%'.$term.'%'])
                  // the four digits of a Ports tile are the end of the MAC without its colon
                  ->orWhereRaw("replace(lower(mac), ':', '') like ?", ['%'.str_replace(':', '', $term).'%'])
                  ->orWhereRaw('lower(hostname) like ?', ['%'.$term.'%']);
            });
        [$sort, $dir] = $this->sorting();
        return ColumnSort::apply($query->get(), $this->columns(), $sort, $dir, Cm::newestFirst());
    }

    public function render()
    {
        if ($this->projectId == -1)
            $this->projectId = Project::getActiveId();

        $list = $this->cms();
        return view('livewire.cms', [
            'CMs' => $list,
            'netboxCount' => NetBoxExport::exportable($list)->count(),
            'netboxMacFile' => NetBoxExport::settings()['mac_style'] !== 'column',
            // not 'sort' and 'dir': the properties of the same names (what the address says) would be the ones the view sees
            'sortKey' => $this->sorting()[0],
            'sortDir' => $this->sorting()[1],
            'total' => Cm::count(),
            'projects' => Project::withCount('cms')->orderBy('name')->get(),
        ]);
    }

    public function delete($id)
    {
        Cm::destroy($id);
        session()->flash('message', 'Cm deleted.');
    }

    /* The files for NetBox (2.21.0): the devices, the interfaces or the MAC addresses of the modules of this list that finished, in the
       order of the list; Settings → Provisioning says what they carry */
    public function exportNetBox(string $kind)
    {
        abort_unless(in_array($kind, NetBoxExport::KINDS, true), 404);
        $list = $this->cms();
        if (NetBoxExport::exportable($list)->isEmpty())
        {
            session()->flash('message', 'No finished module in this list: nothing to export for NetBox.');
            return null;
        }
        return response()->streamDownload(function () use ($kind, $list) {
            echo NetBoxExport::csv($kind, $list);
        }, 'netbox-'.$kind.'-'.date('Ymd').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function exportCSV()
    {
        $cms = $this->cms();
        return response()->streamDownload(function () use ($cms) {
            $fd = fopen('php://output', 'w');
            if (count($cms)) {
                fputcsv($fd, array_keys($cms[0]->getAttributes()));
                foreach ($cms as $cm)
                    fputcsv($fd, $cm->getAttributes());
            }
            fclose($fd);
        }, 'export-cm-'.date('Ymd').'.csv');
    }
}
