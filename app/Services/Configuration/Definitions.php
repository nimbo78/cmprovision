<?php

namespace App\Services\Configuration;

use App\Models\Firmware;
use App\Models\Image;
use App\Models\Label;
use App\Models\Project;
use App\Models\Script;
use App\Services\Sensor\ModuleMark;
use App\Support\StationSetupInput;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Shared object checks for full forms, guided creation and portable configuration. */
class Definitions
{
    public static function scriptRules(): array
    {
        return [
            'name' => 'required|max:255',
            'script_type' => 'required|in:preinstall,postinstall,firstboot',
            'bg' => 'required|boolean',
            'priority' => 'required|numeric',
            'script' => 'required',
        ];
    }

    public static function script(array $input): array
    {
        $data = Validator::make($input, self::scriptRules())->validate();
        $data['bg'] = $data['script_type'] === 'firstboot' ? false : (bool) $data['bg'];
        $data['script'] = str_replace("\r", '', $data['script']);

        return $data;
    }

    public static function labelRules(): array
    {
        return [
            'name' => 'required|max:255',
            'printer_type' => 'in:ftp,command',
            'ftp_hostname' => 'nullable|max:255|required_if:printer_type,ftp',
            'ftp_username' => 'nullable|max:255|required_if:printer_type,ftp',
            'ftp_password' => 'nullable|max:255|required_if:printer_type,ftp',
            'print_command' => 'nullable|max:255|required_if:printer_type,command',
            'file_extension' => 'required|max:32|regex:/^[a-zA-Z0-9]+$/',
            'template' => 'required',
        ];
    }

    public static function label(array $input, ?Label $existing = null): array
    {
        if (!array_key_exists('ftp_password', $input) && $existing !== null) {
            $input['ftp_password'] = $existing->ftp_password;
        }

        return Validator::make($input, self::labelRules())->validate();
    }

    public static function projectRules(?Project $existing = null): array
    {
        return [
            'name' => 'required|max:255',
            'device' => 'required|in:cm4',
            'storage' => 'required|max:255',
            'image_id' => ['bail', 'nullable', 'numeric', function ($attribute, $value, $fail) use ($existing) {
                $image = $value ? Image::find($value) : null;
                if ($image && !$image->isReady() && (int) $value !== (int) $existing?->image_id) {
                    $fail('This image is not ready yet: it is still being fetched, repacked or checked, or it failed.');
                }
            }],
            'label_id' => 'nullable|numeric',
            'firmware' => ['nullable', 'max:255', Rule::in(array_map(
                fn (Firmware $firmware) => $firmware->path, Firmware::all(),
            ))],
            'eeprom_settings' => 'nullable|max:2024',
            'label_moment' => 'required|in:never,preinstall,postinstall,firstboot',
            'selectedScripts' => 'array',
            'selectedPackages' => 'array',
            'verify' => 'required|boolean',
            'os_check' => 'required|boolean',
            'split_image' => 'required|boolean',
            'reprovision' => 'required|boolean',
            'mark_boots' => 'required|integer|min:0|max:'.ModuleMark::MAX_BOOTS,
            'stationEnabled' => 'sometimes|boolean',
            'station' => 'sometimes|array',
        ];
    }

    public static function project(array $input, ?Project $existing = null): array
    {
        $data = Validator::make($input, self::projectRules($existing))->validate();
        $setup = null;
        if ($data['stationEnabled'] ?? false) {
            if (!$data['image_id'] || !$data['verify'] || !preg_match('#^/dev/mmcblk[0-9]+$#D', $data['storage'])) {
                throw ValidationException::withMessages([
                    'stationEnabled' => 'Station setup requires an image, SHA-256 verification and eMMC/SD storage.',
                ]);
            }
            try {
                $setup = StationSetupInput::normalize($data['station'] ?? [], $existing?->station_setup);
            } catch (ValidationException $error) {
                $messages = [];
                foreach ($error->errors() as $field => $errors) {
                    $messages['station.'.$field] = $errors;
                }
                throw ValidationException::withMessages($messages);
            }
        }

        $scripts = $data['selectedScripts'] ?? [];
        $packages = $data['selectedPackages'] ?? [];
        self::projectChecks($data, !$data['os_check'] && Script::whereIn('id', $scripts)->where('script_type', 'firstboot')->exists(), (bool) $packages);

        return [
            'attributes' => [
                'name' => $data['name'],
                'device' => $data['device'],
                'storage' => $data['storage'],
                'image_id' => $data['image_id'] ?: null,
                'label_id' => $data['label_id'] ?: null,
                'label_moment' => $data['label_moment'],
                'eeprom_firmware' => $data['firmware'] ?: null,
                'eeprom_settings' => $data['eeprom_settings'] ? str_replace("\r", '', $data['eeprom_settings']) : null,
                'verify' => (bool) $data['verify'],
                'os_check' => (bool) $data['os_check'],
                'split_image' => (bool) $data['split_image'],
                'reprovision' => (bool) $data['reprovision'],
                'mark_boots' => (int) $data['mark_boots'],
            ],
            'script_ids' => $scripts,
            'package_ids' => $packages,
            'station_setup' => $setup,
        ];
    }

    /** The same option checks before portable dependencies have receiving database identifiers. */
    public static function projectDefinition(array $input, array $objects): array
    {
        $fields = ['name', 'device', 'storage', 'label_moment', 'eeprom_settings', 'verify', 'os_check', 'split_image', 'reprovision', 'mark_boots'];
        $rules = array_intersect_key(self::projectRules(), array_flip($fields));
        $rules['name'] .= '|string';
        $rules['storage'] .= '|string';
        $rules['eeprom_settings'] .= '|string';
        $data = array_replace($input, Validator::make($input, $rules)->validate());
        $firstboot = false;
        foreach ($data['scripts'] as $ref) if (($objects[$ref]['data']['script_type'] ?? '') === 'firstboot') $firstboot = true;
        self::projectChecks($data, $firstboot, (bool) $data['packages']);
        foreach (['verify', 'os_check', 'split_image', 'reprovision'] as $key) $data[$key] = (bool) $data[$key];
        $data['mark_boots'] = (int) $data['mark_boots'];
        $data['eeprom_settings'] = ($data['eeprom_settings'] ?? '') !== '' ? str_replace("\r", '', $data['eeprom_settings']) : null;
        if ($data['station_setup'] !== null) {
            if (!$data['image'] || !$data['verify'] || !preg_match('#^/dev/mmcblk[0-9]+$#D', $data['storage'])) {
                throw ValidationException::withMessages(['stationEnabled' => 'Station setup requires an image, SHA-256 verification and eMMC/SD storage.']);
            }
            $data['station_setup'] = StationSetupInput::saved($data['station_setup']);
        }
        return $data;
    }

    private static function projectChecks(array $data, bool $firstboot, bool $packages): void
    {
        if ($data['os_check']) return;
        $errors = [];
        if ($data['label_moment'] === 'firstboot') $errors['label_moment'] = 'Printing after the OS check needs the OS check.';
        if ($packages) $errors['selectedPackages'] = 'Packages are installed by the OS check: turn it on or clear them.';
        if ($firstboot) $errors['selectedScripts'] = 'First-boot scripts run in the OS check: turn it on or clear them.';
        if ($errors) throw ValidationException::withMessages($errors);
    }
}
