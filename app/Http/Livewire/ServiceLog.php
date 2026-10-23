<?php

namespace App\Http\Livewire;

use App\Services\ServiceLogs;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** The text of one log, following it: every few seconds it asks again and draws only what changed */
class ServiceLog extends Component
{
    #[Locked]
    public $source = '';

    public $paused = false;

    /** of the text on the screen; a poll that finds the same text draws nothing */
    public $hash = '';

    protected $text = '';

    public function mount(ServiceLogs $logs, string $source)
    {
        $this->source = $source;
        $this->load($logs);   // an unknown log throws: the windows pass only the names ServiceLogs lists
    }

    public function refresh(ServiceLogs $logs)
    {
        $text = $logs->read($this->source);
        if (md5($text) === $this->hash) {
            $this->skipRender();
            return;
        }
        $this->text = $text;
        $this->hash = md5($text);
    }

    public function togglePause()
    {
        $this->paused = !$this->paused;
    }

    public function render(ServiceLogs $logs)
    {
        if ($this->text === '') {
            $this->load($logs);   // an action that only redraws (pause): the text is not kept between requests
        }

        return view('livewire.service-log', ['text' => $this->text]);
    }

    protected function load(ServiceLogs $logs): void
    {
        $this->text = $logs->read($this->source);
        $this->hash = md5($this->text);
    }
}
