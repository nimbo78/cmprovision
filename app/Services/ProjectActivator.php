<?php

namespace App\Services;

use App\Models\Firmware;
use App\Models\Project;
use App\Models\Setting;
use RuntimeException;

/**
 * Makes a project the active one. Besides the setting this rebuilds the EEPROM image the modules
 * download (public/uploads/pieeprom.bin) from the project's firmware and settings, so every path
 * that changes the active project (web UI, API) must come through here.
 */
class ProjectActivator
{
    public function activate(Project $project)
    {
        Setting::updateOrCreate(['key' => 'active_project'], ['value' => $project->id]);

        /* File created by a previous cmprovision beta */
        $sigfile = base_path('scriptexecute/pieeprom.sig');
        if (file_exists($sigfile))
            unlink($sigfile);

        $this->rebuildEepromImage($project);
    }

    /* Rebuilds the EEPROM image if the project is the active one (after it was edited) */
    public function refreshIfActive(Project $project)
    {
        if ($project->isActive())
            $this->rebuildEepromImage($project);
    }

    protected function rebuildEepromImage(Project $project)
    {
        $target = public_path('uploads/pieeprom.bin');
        $firmware = $project->eeprom_firmware;

        if (!$firmware)
        {
            if (file_exists($target))
                unlink($target);
            return;
        }

        try
        {
            $data = EepromImage::forFirmware($firmware, (string) $project->eeprom_settings);
        }
        catch (RuntimeException $e)
        {
            /* Do not leave the image of a previous project in place */
            if (file_exists($target))
                unlink($target);
            throw $e;
        }

        $tmp = $target.'.part';
        if (@file_put_contents($tmp, $data) === false || !rename($tmp, $target))
            throw new RuntimeException("Error writing '$target'");

        Setting::updateOrCreate(['key' => 'active_eeprom_sha256'], ['value' => hash('sha256', $data)]);
    }
}
