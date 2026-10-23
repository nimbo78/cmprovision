<?php

namespace App\Http\Livewire;

use App\Models\Firmware;
use App\Services\UsbPrep;
use Livewire\Component;

/* Settings → Provisioning (2.12): the bootloader the USB preparation writes: a firmware of the store and its settings */
class UsbPrepSettings extends Component
{
    public $firmware = '';
    public $settings = '';
    public $notice;
    public $noticeIsError = false;

    public function mount()
    {
        $this->load();
    }

    protected function load()
    {
        $prep = app(UsbPrep::class);
        $this->firmware = (string) $prep->firmware();
        $this->settings = $prep->settings();
    }

    public function save()
    {
        $this->validate(['firmware' => 'required|string', 'settings' => 'required|string|max:2024']);
        try {
            app(UsbPrep::class)->save($this->firmware, $this->settings);
            $this->settings = app(UsbPrep::class)->settings();
            $this->notice = 'Saved: boards prepared from now on get this bootloader.';
            $this->noticeIsError = false;
        } catch (\RuntimeException $e) {
            $this->notice = $e->getMessage();
            $this->noticeIsError = true;
        }
    }

    /* The text of the settings as shipped (network first); it is kept when saved */
    public function useDefaults()
    {
        $this->settings = UsbPrep::DEFAULT_SETTINGS;
        $this->notice = null;
    }

    public function render()
    {
        $prep = app(UsbPrep::class);
        $channels = [];
        foreach (Firmware::channels() as $channel) {
            $channels[$channel] = Firmware::allOfChannel($channel);
        }

        return view('livewire.usb-prep-settings', [
            'channels' => $channels,
            'problem' => $prep->problem(),
            'sha' => $prep->imageSha(),
        ]);
    }
}
