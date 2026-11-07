<?php

namespace App\Services\Configuration;

use App\Models\ConfigurationTransferOperation;
use App\Models\Firmware;
use App\Models\Image;
use App\Models\Label;
use App\Models\Package;
use App\Models\Script;
use App\Models\Project;
use App\Models\EthernetSwitch;
use App\Models\Host;
use App\Models\NotificationChannel;
use App\Models\Setting;
use App\Services\NetworkProfiles;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Resolve operator choices privately; its public result contains only names, statuses and requirements. */
class TransferPreview
{
    private array $known = [];
    private array $fileCache = [];

    public function __construct(private TransferWorkspace $workspace, private TransferDefinitions $definitions)
    {
    }

    public function resolve(ConfigurationTransferOperation $operation, array $manifest, #[\SensitiveParameter] array $choices): array
    {
        Validator::make($choices, ['objects' => 'sometimes|array', 'objects.*' => 'array:action,target,selected,name',
            'objects.*.selected' => 'sometimes|boolean', 'objects.*.name' => 'sometimes|string|max:255',
            'secrets' => 'sometimes|array', 'secrets.*' => 'array', 'secrets.*.*' => 'string|max:16384',
            'interfaces' => 'sometimes|array', 'interfaces.*' => 'array:device,parent',
            'interfaces.*.device' => 'required|string|max:15', 'interfaces.*.parent' => 'nullable|string|max:15'])->validate();
        if (array_diff(array_keys($choices), ['objects', 'secrets', 'interfaces'])) throw new RuntimeException('An import choice contains an unsupported field.');
        $choices['secrets'] ??= [];
        foreach ($choices['secrets'] as $ref => &$secrets) {
            if (!empty($secrets['station_setup.password_hash'])) {
                $password = $secrets['station_setup.password_hash'];
                Validator::make(['password' => $password], ['password' => 'required|string|min:8|max:128|not_regex:/[\x00-\x1f\x7f]/'])->validate();
                $secrets['station_setup.password_hash'] = crypt($password, '$6$rounds=100000$'.bin2hex(random_bytes(8)).'$');
            }
        }
        unset($secrets, $password);
        $choices = array_replace_recursive($operation->options['preview']['choices'] ?? [], $choices);
        $known = array_column($manifest['objects'], null, 'ref');
        $this->known = $known;
        $this->fileCache = [];
        foreach (['objects', 'secrets', 'interfaces'] as $key) {
            foreach (array_keys($choices[$key] ?? []) as $ref) if (!isset($known[$ref])) throw new RuntimeException('A choice names an unknown object.');
        }
        $choices['objects'] ??= [];
        foreach ($choices['objects'] as $ref => &$choice) {
            if (array_key_exists('selected', $choice)) $choice['selected'] = (bool) $choice['selected'];
            if (isset($choice['target']) && !is_int($choice['target']) && !is_string($choice['target'])) throw new RuntimeException('Choose a valid receiving object.');
        }
        unset($choice);
        $selected = [];
        $visit = function (string $ref) use (&$visit, &$selected, $known, $choices) {
            if (isset($selected[$ref]) || ($choices['objects'][$ref]['selected'] ?? true) === false) return;
            $selected[$ref] = true;
            if (in_array($choices['objects'][$ref]['action'] ?? '', ['reuse', 'skip'], true)) return;
            foreach (TransferCatalog::references($known[$ref]) as $dependency => $type) $visit($dependency);
        };
        foreach ($manifest['roots'] as $ref) $visit($ref);
        foreach ($choices['objects'] ?? [] as $ref => $choice) if (($choice['selected'] ?? null) === true) $visit($ref);
        $public = $plans = $snapshot = $requirements = [];
        $changes = 0;
        foreach ($this->ordered($known) as $object) {
            $ref = $object['ref'];
            $type = $object['type'];
            $data = $object['data'];
            $choice = $choices['objects'][$ref] ?? [];
            if (!empty($choice['name']) && in_array($type, ['project', 'script', 'label'], true)) $data['name'] = $choice['name'];
            $isSelected = isset($selected[$ref]);
            $missing = [];
            foreach ($choices['secrets'][$ref] ?? [] as $path => $secret) {
                if (!in_array($path, TransferCatalog::secretPaths($type, $data), true) || !is_string($secret) || strlen($secret) > 16384) {
                    throw new RuntimeException('A supplied secret has an unsupported field or value.');
                }
                Arr::set($data, $path, $secret);
            }
            foreach ($object['omitted_secrets'] as $path) if (Arr::get($data, $path) === null || Arr::get($data, $path) === '') $missing[] = $path;
            $normalized = null;
            $errors = [];
            if (!$missing) {
                try { $normalized = $this->normalize($type, $data); }
                catch (ValidationException $error) { $errors = $error->errors(); }
            }
            $candidates = $this->candidates($object, $normalized === null ? null : $this->comparison($type, $normalized, $plans), $choice['target'] ?? null);
            $snapshot[$ref] = array_map(fn ($candidate) => ['id' => $candidate['id'],
                'hash' => hash('sha256', self::canonical($candidate))], $candidates);
            $matches = $conflicts = [];
            foreach ($candidates as $candidate) {
                $equal = empty($candidate['invalid']) && (isset($object['file']) ? ($candidate['file']['sha256'] === $object['file']['sha256'] && $candidate['file']['size'] === $object['file']['size'])
                    : ($normalized !== null && self::canonical($candidate['data']) === self::canonical($this->comparison($type, $normalized, $plans))));
                if ($equal) $matches[] = $candidate;
                elseif ($type === 'host'
                    ? ($candidate['data']['mac'] === strtolower($data['mac']) || $candidate['data']['ip'] === $data['ip'])
                    : $candidate['name'] === $this->name($object)) $conflicts[] = $candidate;
            }
            $action = $choice['action'] ?? ($matches ? 'reuse' : ($conflicts ? 'copy' : 'create'));
            if (!isset($choice['action']) && $conflicts && in_array($type, ['settings', 'switch', 'host'], true)) $action = 'skip';
            if (in_array($type, ['settings', 'switch', 'host'], true) && $action === 'copy') {
                $errors['action'] = ['This configuration has a fixed identity. Choose reuse, replacement or skip.'];
            }
            if (in_array($type, ['settings', 'switch', 'host'], true) && $action === 'create' && ($matches || $conflicts)) {
                $errors['action'] = ['This configuration already exists. Choose explicit replacement, reuse or skip.'];
            }
            if (!$isSelected) $action = 'skip';
            if (!in_array($action, ['create', 'reuse', 'copy', 'skip', 'replace'], true)) throw new RuntimeException('Choose an available import action.');
            $target = $choice['target'] ?? ($action === 'reuse' && $matches ? $matches[0]['id'] : null);
            $targetRow = null;
            foreach ($candidates as $candidate) if ((string) $candidate['id'] === (string) $target) $targetRow = $candidate;
            if ($targetRow !== null) $target = $targetRow['id'];
            if ($isSelected && in_array($action, ['reuse', 'replace'], true) && $targetRow === null) {
                $requirements[] = $this->requirement($ref, 'target', 'Choose an existing receiving object.');
            }
            if ($action === 'reuse' && $targetRow !== null && empty($targetRow['invalid'])) {
                $missing = $errors = [];
                $normalized = $targetRow['data'];
                if (isset($object['file']) && !in_array($target, array_column($matches, 'id'), true)) {
                    $requirements[] = $this->requirement($ref, 'file', 'Map this file to one with the same bytes and SHA-256.');
                }
            }
            if ($action === 'reuse' && !empty($targetRow['invalid'])) $errors['target'] = ['This existing object is incomplete or no longer verified. Choose another object.'];
            $interface = $choices['interfaces'][$ref] ?? null;
            if ($type === 'network' && $isSelected && $action !== 'skip') {
                $devices = app(NetworkProfiles::class)->ethernetDevices() ?? [];
                $physical = $data['type'] === 'vlan' ? ($interface['parent'] ?? '') : ($interface['device'] ?? '');
                if (!is_array($interface) || !in_array($physical, $devices, true)
                    || !preg_match('/^[A-Za-z0-9_.-]{1,15}$/D', $interface['device'] ?? '')) {
                    $errors['interface'] = ['Map the Ethernet interface or VLAN parent to a receiving Ethernet device.'];
                }
            }
            $fileStatus = null;
            $file = $operation->options['files'][$ref] ?? null;
            if (isset($object['file'])) {
                $fileStatus = $matches ? 'available' : 'missing';
                if ($file !== null) {
                    $path = $this->workspace->path($operation, $file['member']);
                    if (is_file($path) && filesize($path) === $file['size'] && hash_equals($file['sha256'], $object['file']['sha256'])) $fileStatus = 'verified';
                }
                if ($action === 'reuse' && $targetRow !== null && $matches) $fileStatus = 'available';
                if ($isSelected && $action !== 'skip' && $fileStatus === 'missing') {
                    $requirements[] = $this->requirement($ref, 'file', 'Upload the missing file normally or choose a verified existing copy.');
                }
            }
            if ($isSelected && $action !== 'skip') {
                foreach ($missing as $path) $requirements[] = $this->requirement($ref, $path, 'Supply the excluded secret or explicitly reuse an existing complete object.');
                foreach ($errors as $field => $messages) $requirements[] = $this->requirement($ref, $field, $messages[0]);
                foreach ($action === 'reuse' ? [] : TransferCatalog::references($object) as $dependency => $dependencyType) {
                    if (!isset($selected[$dependency]) || ($choices['objects'][$dependency]['action'] ?? '') === 'skip') {
                        $requirements[] = $this->requirement($ref, $dependency, 'This selected object requires its excluded dependency.');
                    }
                }
                $changes++;
            }
            $plans[$ref] = ['ref' => $ref, 'type' => $type, 'selected' => $isSelected, 'action' => $action,
                'target' => $target, 'data' => $normalized ?? $data, 'file' => $file,
                'file_source' => $matches[0]['id'] ?? null, 'interface' => $interface];
            $public[] = ['ref' => $ref, 'type' => $type, 'name' => $this->name($object), 'selected' => $isSelected,
                'action' => $action, 'target' => $target, 'file_status' => $fileStatus,
                'matches' => array_map(fn ($candidate) => Arr::only($candidate, ['id', 'name']), $matches),
                'conflicts' => array_map(fn ($candidate) => Arr::only($candidate, ['id', 'name']), $conflicts),
                'requires' => array_keys(TransferCatalog::references($object)), 'interface' => $interface];
        }
        $fingerprint = hash_hmac('sha256', self::canonical([$operation->id, $operation->options['manifest_sha256'], $choices, $plans, $snapshot]), (string) config('app.key'));
        return ['public' => ['objects' => $public, 'requirements' => $requirements, 'can_apply' => !$requirements && $changes > 0, 'fingerprint' => $fingerprint],
            'private' => ['choices' => $choices, 'objects' => $plans, 'receiver' => $snapshot, 'fingerprint' => $fingerprint]];
    }

    private function normalize(string $type, array $data): array
    {
        return $this->definitions->normalize($type, $data, $this->known);
    }

    private function candidates(array $object, ?array $wanted = null, int|string|null $target = null): array
    {
        $type = $object['type'];
        $class = ['script' => Script::class, 'label' => Label::class, 'image' => Image::class, 'package' => Package::class,
            'project' => Project::class, 'switch' => EthernetSwitch::class, 'host' => Host::class, 'notification' => NotificationChannel::class][$type] ?? null;
        $result = [];
        if ($class !== null) {
            foreach ($class::all() as $model) {
                if (isset($object['file']) && $model->filename !== $object['data']['filename'] && $model->sha256 !== $object['file']['sha256']) continue;
                $data = [];
                $localObjects = $this->known;
                if ($type === 'project') {
                    foreach (array_slice(TransferCatalog::FIELDS['project'], 0, 10) as $key) $data[$key] = $model->$key;
                    $scripts = $model->scripts()->orderBy('scripts.id')->get();
                    $data += ['image' => $model->image_id, 'label' => $model->label_id, 'firmware' => $model->eeprom_firmware,
                        'scripts' => $scripts->pluck('id')->all(), 'packages' => $model->packages()->orderBy('packages.id')->pluck('packages.id')->all(),
                        'station_setup' => $model->station_setup];
                    foreach ($scripts as $script) $localObjects[$script->id] = ['data' => ['script_type' => $script->script_type]];
                } elseif ($type === 'switch') $data = Arr::only($model->config(), TransferCatalog::FIELDS['switch']);
                else foreach (TransferCatalog::FIELDS[$type] as $key) $data[$key] = $model->$key;
                $invalid = false;
                try { $data = $this->definitions->normalize($type, $data, $localObjects); }
                catch (ValidationException $error) { $invalid = true; }
                $candidate = ['id' => $model->id, 'name' => $this->name(['ref' => $object['ref'], 'data' => $data]), 'data' => $data, 'invalid' => $invalid];
                if ($type === 'project' && ((string) $model->id === (string) $target
                    || $wanted !== null && self::canonical($wanted) === self::canonical($data))) {
                    try { $candidate['dependencies'] = $this->projectFacts($model); }
                    catch (RuntimeException|ValidationException $error) { $candidate['invalid'] = true; }
                }
                if (isset($object['file'])) {
                    if ($type === 'image' && (!$model->isReady() || !$model->uncompressed_sha256 || !$model->uncompressed_size)) continue;
                    $path = $type === 'image' ? $model->imagepath() : $model->path();
                    $root = $type === 'image' ? public_path('uploads') : public_path('uploads/packages');
                    $file = $this->localFile($path, $root);
                    if ($file === null) continue;
                    $candidate['file'] = $file;
                    if (!hash_equals((string) $model->sha256, $file['sha256'])) $candidate['invalid'] = true;
                }
                $result[] = $candidate;
            }
        } elseif ($type === 'settings') {
            $group = $object['data']['group'];
            $keys = match ($group) {
                'netbox' => array_map(fn ($key) => 'netbox_'.$key, array_keys(\App\Services\NetBoxExport::DEFAULTS)),
                'display' => ['fpms_theme', 'fpms_ports_map', 'fpms_color_mode', 'hdmi_color_mode'],
                'usb_prep' => ['usb_prep_firmware', 'usb_prep_settings'], 'sensor_accounts' => ['reprovision_accounts'], 'mark_key' => ['module_mark_key'],
            };
            if (Setting::whereIn('key', $keys)->exists()) {
                $manifest = app(TransferCatalog::class)->export(['groups' => [$group]], true);
                $settings = collect($manifest['objects'])->firstWhere('type', 'settings');
                $data = $settings['data'];
                $invalid = false;
                try { $data = $this->definitions->normalize('settings', $data); }
                catch (ValidationException $error) { $invalid = true; }
                if ($group === 'usb_prep' && isset($data['values']['firmware'])) {
                    $firmware = collect($manifest['objects'])->firstWhere('type', 'firmware');
                    $data['values']['firmware'] = $firmware['data']['channel'].'/'.$firmware['data']['name'];
                }
                $result[] = ['id' => $group, 'name' => $group, 'data' => $data, 'invalid' => $invalid];
            }
        } elseif ($type === 'firmware') {
            foreach (Firmware::all() as $firmware) {
                $file = $this->localFile(Firmware::basedir().'/'.$firmware->path, Firmware::basedir());
                if ($file !== null) $result[] = ['id' => $firmware->path, 'name' => $firmware->name,
                    'data' => ['name' => $firmware->name, 'channel' => $firmware->channel], 'file' => $file];
            }
        }
        return $result;
    }

    private function localFile(string $path, string $root): ?array
    {
        $actual = realpath($path);
        $store = realpath($root);
        if (is_link($path) || !is_file($path) || $actual === false || $store === false || !str_starts_with($actual, $store.'/')) return null;
        $size = filesize($actual);
        if ($size === false || $size < 1 || $size > TransferCatalog::MAX_ARCHIVE_BYTES) return null;
        if (isset($this->fileCache[$actual])) return $this->fileCache[$actual];
        $sha = hash_file('sha256', $actual);
        return $sha === false ? null : $this->fileCache[$actual] = ['size' => $size, 'sha256' => $sha];
    }

    private function comparison(string $type, array $data, array $plans): array
    {
        $target = fn ($ref) => $ref === null ? null : ($plans[$ref]['target'] ?? 'new:'.$ref);
        if ($type === 'project') {
            foreach (['image', 'label', 'firmware'] as $key) $data[$key] = $target($data[$key]);
            foreach (['scripts', 'packages'] as $key) {
                $data[$key] = array_map($target, $data[$key]);
                sort($data[$key]);
            }
        }
        if ($type === 'settings' && $data['group'] === 'usb_prep') $data['values']['firmware'] = $target($data['values']['firmware']);
        return $data;
    }

    private function ordered(array $objects): array
    {
        $ordered = [];
        $visit = function ($ref) use (&$visit, &$ordered, $objects) {
            if (isset($ordered[$ref])) return;
            foreach (TransferCatalog::references($objects[$ref]) as $dependency => $type) $visit($dependency);
            $ordered[$ref] = $objects[$ref];
        };
        foreach (array_keys($objects) as $ref) $visit($ref);
        return array_values($ordered);
    }

    private function name(array $object): string
    {
        $data = $object['data'];
        return (string) ($data['name'] ?? $data['filename'] ?? $data['group'] ?? $data['host'] ?? (($data['hostname'] ?? '') ?: null) ?? $data['ip'] ?? $object['ref']);
    }

    private function projectFacts(Project $project): string
    {
        $facts = [];
        if ($project->image_id) {
            $image = $project->image;
            if ($image === null || !$image->isReady() || !$image->sha256 || !$image->uncompressed_sha256 || !$image->uncompressed_size) {
                throw new RuntimeException('The receiving project image is not fully verified.');
            }
            $file = $this->localFile($image->imagepath(), public_path('uploads'));
            if ($file === null || !hash_equals($image->sha256, $file['sha256'])) throw new RuntimeException('The receiving project image changed.');
            $facts['image'] = $file + ['uncompressed_sha256' => $image->uncompressed_sha256, 'uncompressed_size' => $image->uncompressed_size];
        }
        if ($project->label_id) {
            $label = $project->label;
            if ($label === null) throw new RuntimeException('The receiving project label is missing.');
            $fields = [];
            foreach (TransferCatalog::FIELDS['label'] as $key) $fields[$key] = $label->$key;
            $facts['label'] = $this->definitions->normalize('label', $fields);
        }
        foreach ($project->scripts as $script) {
            $fields = [];
            foreach (TransferCatalog::FIELDS['script'] as $key) $fields[$key] = $script->$key;
            $facts['scripts'][$script->id] = $this->definitions->normalize('script', $fields);
        }
        foreach ($project->packages as $package) {
            $file = $this->localFile($package->path(), public_path('uploads/packages'));
            if ($file === null || !hash_equals($package->sha256, $file['sha256'])) throw new RuntimeException('A receiving project package changed.');
            $facts['packages'][$package->id] = $file;
        }
        if ($project->eeprom_firmware) {
            $firmware = collect(Firmware::all())->first(fn ($entry) => $entry->path === $project->eeprom_firmware);
            $file = $firmware === null ? null : $this->localFile(Firmware::basedir().'/'.$firmware->path, Firmware::basedir());
            if ($file === null) throw new RuntimeException('The receiving project firmware is missing.');
            $facts['firmware'] = $file;
        }
        return hash('sha256', self::canonical($facts));
    }

    private function requirement(string $ref, string $field, string $message): array
    {
        return compact('ref', 'field', 'message');
    }

    public static function canonical(#[\SensitiveParameter] mixed $value): string
    {
        if (!is_array($value)) return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $normalize = function ($item) use (&$normalize) {
            if (!is_array($item)) return $item;
            if (!array_is_list($item)) ksort($item, SORT_STRING);
            foreach ($item as $key => $child) $item[$key] = $normalize($child);
            return $item;
        };
        return json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
