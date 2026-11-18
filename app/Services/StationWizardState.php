<?php

namespace App\Services;

use App\Models\EthernetSwitch;
use App\Models\Project;
use App\Models\Setting;

/** Reads real configuration. Wizard progress is never evidence that a station is ready. */
class StationWizardState
{
    public function read(): array
    {
        $changes = app(NetworkChanges::class);
        $network = $changes->available() ? $changes->status() : ['ok' => false];
        $services = ($network['ok'] ?? false) ? $changes->roleState() : ['ok' => false];
        $reader = app(NetworkProfiles::class);
        $profiles = ($services['ok'] ?? false) ? $reader->active() : null;
        $standState = $reader->standState($profiles);
        $stand = $standState['stand'];
        $pending = ($network['ok'] ?? false) ? (bool) ($network['result']['pending'] ?? true) : null;
        $role = 'unknown'; $blockers = []; $warnings = [];
        $dhcp = $services['result']['dhcp'] ?? null; $usb = $services['result']['usb'] ?? null;
        $valid = fn ($state) => is_array($state) && is_bool($state['active'] ?? null) && is_bool($state['boot_enabled'] ?? null)
            && in_array($state['enabled'] ?? null, ['enabled', 'disabled', 'masked'], true);
        if (($services['ok'] ?? false) && $profiles !== null && $standState['known'] && $valid($dhcp) && $valid($usb)) {
            $off = fn ($state) => !$state['active'] && !$state['boot_enabled'] && $state['enabled'] !== 'enabled';
            $consistentUsb = ($usb['active'] && $usb['boot_enabled'] && $usb['enabled'] === 'enabled') || $off($usb);
            if (($stand['address'] ?? null) === '172.20.0.1' && $dhcp['active'] && $dhcp['boot_enabled'] && $dhcp['enabled'] === 'enabled' && $consistentUsb) $role = 'primary';
            elseif ($off($dhcp) && $off($usb)) $role = ($stand['address'] ?? null) === '172.20.0.2' ? 'secondary' : ($stand === null ? 'disabled' : 'unknown');
        }
        if ($pending !== false) $blockers[] = $pending === true ? 'Confirm or restore the pending network change before continuing.' : 'Network transaction status is unavailable. Refresh or check the station console.';
        if ($role === 'unknown') $blockers[] = 'The station role or boot service state is unavailable or inconsistent.';
        $project = $this->projectReady();
        if ($role === 'primary' && !$project['ready']) array_push($blockers, ...$project['errors']);
        if (app(StationUpdatesControl::class)->pending() || app(StationBackupsControl::class)->pending() || app(StationPowerControl::class)->pending()) $blockers[] = 'Station maintenance is in progress. Finish it before completing setup.';
        $access = app(StationAccessControl::class);
        if ($access->available()) {
            $result = $access->status();
            if (($result['ok'] ?? false) && ($result['result']['pending'] ?? null)) $blockers[] = 'Apply or cancel the prepared SSH access request before completing setup.';
        }
        $networkStatus = app(NetworkStatus::class)->read();
        $links = $networkStatus['links'] ?? [];
        $uplink = $networkStatus['uplink'] ?? null;
        $switches = EthernetSwitch::count();
        if (!$switches) $warnings[] = 'No managed switch configured. Port mapping and PoE controls will be unavailable.';
        if (!$uplink) $warnings[] = 'No management uplink information was reported. Keep the working browser/SSH connection.';
        return ['role' => $role, 'pending' => $pending, 'uplink' => $uplink, 'stand' => $stand ? array_intersect_key($stand, array_flip(['device', 'address', 'type', 'vlan_id'])) : null,
            'services' => ['dhcp' => $dhcp, 'usb' => $usb], 'links' => $links, 'switches' => $switches, 'project' => $project,
            'blockers' => array_values(array_unique($blockers)), 'warnings' => $warnings];
    }

    public function projectReady(): array
    {
        $project = Project::getActive(); $errors = [];
        if (!$project) return ['ready' => false, 'name' => null, 'errors' => ['Prepare and activate a provisioning project.']];
        $image = $project->image;
        if (!$image && !$project->eeprom_firmware) $errors[] = 'The active project needs an image or EEPROM firmware.';
        if ($project->image_id && (!$image || !$image->isReady() || !$image->filesize())) $errors[] = 'The active project image is missing or is still being prepared.';
        if ($image && !preg_match('/\A[a-f0-9]{64}\z/', (string) $image->sha256)) $errors[] = 'Wait for the image SHA256 calculation to finish.';
        if ($image && $project->verify && !preg_match('/\A[a-f0-9]{64}\z/', (string) $image->uncompressed_sha256)) $errors[] = 'Wait for the uncompressed image SHA256 needed by verification.';
        if ($image && $project->split_image && !$image->splitReady()) $errors[] = 'Wait for the split image to be prepared.';
        if ($project->eeprom_firmware) {
            try {
                $expected = hash('sha256', EepromImage::forFirmware($project->eeprom_firmware, (string) $project->eeprom_settings));
                $path = public_path('uploads/pieeprom.bin');
                if (!is_file($path) || !hash_equals($expected, hash_file('sha256', $path)) || Setting::find('active_eeprom_sha256')?->value !== $expected) {
                    $errors[] = 'Activate the project again to prepare its EEPROM firmware.';
                }
            } catch (\RuntimeException $error) { $errors[] = 'The active project EEPROM firmware cannot be prepared. Check its firmware and settings.'; }
        }
        if ($project->label_moment !== 'never' && !$project->label) $errors[] = 'The active project label is missing.';
        return ['ready' => !$errors, 'name' => $project->name, 'errors' => $errors];
    }

    public static function completed(): bool
    {
        return Setting::whereKey('station_wizard_completed')->exists();
    }

    public static function invitation(): bool
    {
        return !self::completed() && !Project::exists() && !EthernetSwitch::exists();
    }
}
