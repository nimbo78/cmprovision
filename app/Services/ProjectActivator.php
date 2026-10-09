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

        /* Only images from the firmware store: the value may come from the API */
        $known = array_map(function ($f) { return $f->path; }, Firmware::all());
        $source = Firmware::basedir().'/'.$firmware;
        $data = in_array($firmware, $known, true) && is_file($source) ? @file_get_contents($source) : false;
        if ($data === false)
        {
            /* Do not leave the image of a previous project in place */
            if (file_exists($target))
                unlink($target);
            throw new RuntimeException("EEPROM firmware '$firmware' is no longer available in the firmware store; the module EEPROM will not be updated");
        }

        if (!EepromImage::setConfig($data, EepromImage::normalizeConfig($project->eeprom_settings)))
            throw new RuntimeException("EEPROM firmware '$firmware' could not be parsed for its configuration");

        $tmp = $target.'.part';
        if (@file_put_contents($tmp, $data) === false || !rename($tmp, $target))
            throw new RuntimeException("Error writing '$target'");

        Setting::updateOrCreate(['key' => 'active_eeprom_sha256'], ['value' => hash('sha256', $data)]);
    }
}
