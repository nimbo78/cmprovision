<?php

namespace App\Http\Livewire;

use App\Models\Cm;
use App\Models\Project;
use Livewire\Attributes\Title;
use Livewire\Component;

/* The list of CMs: newest first, by project, searchable by a part of the serial or the MAC */
#[Title('CMs')]
class Cms extends Component
{
    public $projectId = -1;
    public $search = '';

    protected function cms()
    {
        $query = Cm::with('project')->orderByDesc('id');
        if ($this->projectId)
            $query->where('project_id', $this->projectId);
        $term = strtolower(trim((string) $this->search));
        if ($term !== '')
            $query->where(function ($q) use ($term) {
                $q->whereRaw('lower(serial) like ?', ['%'.$term.'%'])->orWhereRaw('lower(mac) like ?', ['%'.$term.'%'])
                  ->orWhereRaw('lower(hostname) like ?', ['%'.$term.'%']);
            });
        return $query->get();
    }

    public function render()
    {
        if ($this->projectId == -1)
            $this->projectId = Project::getActiveId();

        return view('livewire.cms', [
            'CMs' => $this->cms(),
            'total' => Cm::count(),
            'projects' => Project::withCount('cms')->orderBy('name')->get(),
        ]);
    }

    public function delete($id)
    {
        Cm::destroy($id);
        session()->flash('message', 'Cm deleted.');
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
