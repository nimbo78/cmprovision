<?php

namespace App\Services\Configuration;

use App\Models\EthernetSwitch;
use App\Models\Firmware;
use App\Models\Host;
use App\Models\Image;
use App\Models\Label;
use App\Models\NotificationChannel;
use App\Models\Package;
use App\Models\Project;
use App\Models\Script;
use App\Models\Setting;
use App\Services\Fpms;
use App\Services\NetBoxExport;
use App\Services\NetworkProfiles;
use App\Services\Sensor\ModuleMark;
use App\Services\Sensor\SavedAccounts;
use App\Services\UsbPrep;
use App\Services\Wallboard;
use App\Support\AppVersion;
use App\Support\FpmsPanelSettings;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Explicit portable definitions; database rows, polling results and machine paths are never serialized wholesale. */
class TransferCatalog
{
    public const FORMAT = 'cmprovision-configuration';
    public const SCHEMA = 1;
    public const MAX_JSON_BYTES = 16 * 1024 * 1024;
    public const MAX_ARCHIVE_BYTES = 64 * 1024 * 1024 * 1024;
    public const MAX_ENTRIES = 4096;
    public const MAX_OBJECTS = 2048;
    public const GROUPS = [
        'netbox' => 'NetBox export defaults', 'display' => 'HDMI and front-panel preferences',
        'usb_prep' => 'USB network-boot preparation', 'sensor_accounts' => 'Saved re-provision accounts',
        'mark_key' => 'Shared sensor recognition key', 'notifications' => 'Notification channels',
        'switches' => 'Stand switches', 'hosts' => 'DHCP reservations', 'network' => 'Ethernet and VLAN drafts',
    ];
    public const FIELDS = [
        'project' => ['name', 'device', 'storage', 'label_moment', 'eeprom_settings', 'verify', 'os_check', 'split_image',
            'reprovision', 'mark_boots', 'image', 'label', 'scripts', 'packages', 'firmware', 'station_setup'],
        'script' => ['name', 'script_type', 'bg', 'priority', 'script'],
        'label' => ['name', 'printer_type', 'print_command', 'ftp_hostname', 'ftp_username', 'ftp_password', 'template', 'file_extension'],
        'image' => ['filename', 'filename_extension', 'uncompressed_sha256', 'uncompressed_size'],
        'package' => ['filename', 'deb_package', 'deb_version', 'deb_architecture'],
        'firmware' => ['name', 'channel'], 'settings' => ['group', 'values'],
        'switch' => ['host', 'version', 'community', 'user', 'auth_protocol', 'auth_password', 'priv_protocol', 'priv_password', 'method', 'write'],
        'host' => ['mac', 'ip', 'hostname'], 'notification' => ['name', 'type', 'enabled', 'events', 'settings'],
        'network' => ['name', 'device', 'type', 'vlan_id', 'parent', 'draft', 'ipv4', 'ipv6'],
    ];
    public const NOTIFICATION_FIELDS = ['url', 'server_url', 'token', 'channel', 'channel_id', 'bot_token', 'chat_id',
        'topic_id', 'tls_ca', 'tls_ca_for', 'tls_insecure'];
    private array $objects = [];
    private array $references = [];
    private array $sources = [];

    public function choices(): array
    {
        return ['projects' => Project::orderBy('name')->get(['id', 'name'])->toArray(),
            'scripts' => Script::orderBy('name')->get(['id', 'name'])->toArray(),
            'labels' => Label::orderBy('name')->get(['id', 'name'])->toArray(), 'groups' => self::GROUPS];
    }

    public function export(array $selection, bool $includeSecrets): array
    {
        Validator::make($selection, [
            'projects' => 'sometimes|array|max:1000', 'projects.*' => 'integer|distinct|exists:projects,id',
            'scripts' => 'sometimes|array|max:1000', 'scripts.*' => 'integer|distinct|exists:scripts,id',
            'labels' => 'sometimes|array|max:1000', 'labels.*' => 'integer|distinct|exists:labels,id',
            'groups' => 'sometimes|array', 'groups.*' => [Rule::in(array_keys(self::GROUPS)), 'distinct'],
            'binaries' => 'sometimes|array', 'binaries.*' => [Rule::in(['images', 'packages', 'firmware']), 'distinct'],
        ])->validate();
        $this->objects = $this->references = $this->sources = [];
        $roots = [];
        foreach ($selection['projects'] ?? [] as $id) $roots[] = $this->project(Project::findOrFail($id), $includeSecrets);
        foreach ($selection['scripts'] ?? [] as $id) $roots[] = $this->script(Script::findOrFail($id));
        foreach ($selection['labels'] ?? [] as $id) $roots[] = $this->label(Label::findOrFail($id), $includeSecrets);
        foreach ($selection['groups'] ?? [] as $group) {
            array_push($roots, ...$this->group($group, $includeSecrets));
        }
        $manifest = ['format' => self::FORMAT, 'schema' => self::SCHEMA, 'source' => AppVersion::text(),
            'created_at' => now()->toIso8601String(), 'roots' => array_values(array_unique($roots)),
            'objects' => array_values($this->objects)];
        return $this->validate($manifest);
    }

    /** Private server paths for the immediately preceding export, never included in its manifest. */
    public function fileSources(): array
    {
        return $this->sources;
    }

    public function validate(array $manifest): array
    {
        Validator::make($manifest, [
            'format' => ['required', Rule::in([self::FORMAT])], 'schema' => ['required', 'integer', Rule::in([self::SCHEMA])],
            'source' => 'required|string|max:100', 'created_at' => 'required|string|max:50|date',
            'roots' => 'required|array|min:1|max:'.self::MAX_OBJECTS, 'roots.*' => 'required|string|distinct|max:64',
            'objects' => 'required|array|min:1|max:'.self::MAX_OBJECTS,
        ])->validate();
        if (($manifest['schema'] ?? null) !== self::SCHEMA || !array_is_list($manifest['roots']) || !array_is_list($manifest['objects'])) $this->invalid();
        $this->onlyKeys($manifest, ['format', 'schema', 'source', 'created_at', 'roots', 'objects']);
        $objects = [];
        foreach ($manifest['objects'] as $object) {
            if (!is_array($object)) $this->invalid();
            $this->onlyKeys($object, ['ref', 'type', 'data', 'omitted_secrets', 'file']);
            $type = $object['type'] ?? '';
            $ref = $object['ref'] ?? '';
            if (!is_string($type) || !isset(self::FIELDS[$type]) || !is_string($ref)
                || !preg_match('/^'.preg_quote($type, '/').'-[1-9][0-9]{0,4}$/D', $ref) || isset($objects[$ref])
                || !is_array($object['data'] ?? null) || !is_array($object['omitted_secrets'] ?? null)
                || !array_is_list($object['omitted_secrets'])) $this->invalid();
            $this->onlyKeys($object['data'], self::FIELDS[$type]);
            $this->shape($type, $object['data']);
            foreach ($object['omitted_secrets'] as $path) {
                if (!is_string($path) || !in_array($path, self::secretPaths($type, $object['data']), true)
                    || Arr::has($object['data'], $path)) $this->invalid();
            }
            if ($type === 'settings' && !in_array($object['data']['group'] ?? '', ['netbox', 'display', 'usb_prep', 'sensor_accounts', 'mark_key'], true)) $this->invalid();
            if (in_array($type, ['image', 'package', 'firmware'], true)) {
                $file = $object['file'] ?? null;
                if (!is_array($file)) $this->invalid();
                $this->onlyKeys($file, ['member', 'size', 'sha256', 'included']);
                if (!is_string($file['member'] ?? null) || !preg_match('#^files/'.preg_quote($ref, '#').'\.(gz|xz|bz2|deb|bin)$#D', $file['member'])
                    || !is_int($file['size'] ?? null) || $file['size'] < 1 || $file['size'] > self::MAX_ARCHIVE_BYTES
                    || !is_string($file['sha256'] ?? null) || !preg_match('/^[0-9a-f]{64}$/D', $file['sha256'])
                    || !is_bool($file['included'] ?? null)) $this->invalid();
            } elseif (array_key_exists('file', $object)) $this->invalid();
            $objects[$ref] = $object;
        }
        foreach ($manifest['roots'] as $root) if (!isset($objects[$root])) $this->invalid();
        foreach ($objects as $object) {
            foreach (self::references($object) as $ref => $type) {
                if (!isset($objects[$ref]) || $objects[$ref]['type'] !== $type) $this->invalid();
            }
        }
        if (strlen(json_encode($manifest, JSON_THROW_ON_ERROR)) > self::MAX_JSON_BYTES) {
            throw new RuntimeException('The configuration exceeds the 16 MiB manifest limit. Select fewer objects.');
        }
        return $manifest;
    }

    /** References keyed by export-local identifier; source and receiving database IDs never enter these definitions. */
    public static function references(array $object): array
    {
        $data = $object['data'];
        $result = [];
        if ($object['type'] === 'project') {
            foreach (['image', 'label', 'firmware'] as $type) {
                if (!empty($data[$type]) && is_string($data[$type])) $result[$data[$type]] = $type;
            }
            foreach (['scripts' => 'script', 'packages' => 'package'] as $key => $type) {
                foreach (is_array($data[$key] ?? null) ? $data[$key] : [] as $ref) {
                    if (is_string($ref)) $result[$ref] = $type;
                }
            }
        }
        if ($object['type'] === 'settings' && ($data['group'] ?? '') === 'usb_prep' && is_string($data['values']['firmware'] ?? null)) {
            $result[$data['values']['firmware']] = 'firmware';
        }
        return $result;
    }

    public static function secretPaths(string $type, array $data): array
    {
        if ($type === 'label') return ['ftp_password'];
        if ($type === 'project') return ['station_setup.password_hash', 'station_setup.wifi.password'];
        if ($type === 'switch') return ['community', 'auth_password', 'priv_password', 'write.community', 'write.auth_password', 'write.priv_password'];
        if ($type === 'notification') return ['settings.url', 'settings.token', 'settings.bot_token'];
        if ($type === 'settings' && ($data['group'] ?? '') === 'mark_key') return ['values.key'];
        if ($type === 'settings' && ($data['group'] ?? '') === 'sensor_accounts') {
            return array_map(fn ($index) => 'values.accounts.'.$index.'.password', array_keys($data['values']['accounts'] ?? []));
        }
        return [];
    }

    private function project(Project $project, bool $secrets): string
    {
        $ref = $this->reference('project', $project->id);
        if (isset($this->objects[$ref])) return $ref;
        $data = $this->fields($project, array_slice(self::FIELDS['project'], 0, 10));
        $data += ['image' => $project->image_id ? $this->image($project->image()->firstOrFail()) : null,
            'label' => $project->label_id ? $this->label($project->label()->firstOrFail(), $secrets) : null,
            'scripts' => [], 'packages' => [], 'firmware' => $project->eeprom_firmware ? $this->firmware($project->eeprom_firmware) : null,
            'station_setup' => $project->station_setup];
        foreach ($project->orderedScripts()->get() as $script) $data['scripts'][] = $this->script($script);
        foreach ($project->packages()->orderBy('packages.id')->get() as $package) $data['packages'][] = $this->package($package);
        if (is_array($data['station_setup'])) {
            $data['station_setup'] = Arr::only($data['station_setup'], ['schema', 'hostname', 'username', 'password_hash', 'keys', 'ssh', 'wifi']);
            if (is_array($data['station_setup']['wifi'] ?? null)) {
                $data['station_setup']['wifi'] = Arr::only($data['station_setup']['wifi'], ['ssid', 'security', 'password', 'country', 'hidden']);
            }
        }
        return $this->put('project', $project->id, $data, $secrets);
    }

    private function script(Script $script): string
    {
        return $this->put('script', $script->id, Definitions::script($this->fields($script, self::FIELDS['script'])), true);
    }

    private function label(Label $label, bool $secrets): string
    {
        return $this->put('label', $label->id, $this->fields($label, self::FIELDS['label']), $secrets);
    }

    private function image(Image $image): string
    {
        if (!$image->isReady()) throw new RuntimeException('An image is still being downloaded or checked. Finish its verification before exporting.');
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,200}$/D', $image->filename_on_server)
            || !in_array($image->filename_extension, ['gz', 'xz', 'bz2'], true)) throw new RuntimeException('An image has an unsupported file name or format.');
        $ref = $this->put('image', $image->id, $this->fields($image, self::FIELDS['image']), true);
        $this->file($ref, $image->imagepath(), public_path('uploads'), $image->filename_extension, $image->sha256 ?: null);
        return $ref;
    }

    private function package(Package $package): string
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,200}$/D', $package->filename_on_server)) throw new RuntimeException('A package has an unsupported file name.');
        $ref = $this->put('package', $package->id, $this->fields($package, self::FIELDS['package']), true);
        $this->file($ref, $package->path(), public_path('uploads/packages'), 'deb', $package->sha256);
        return $ref;
    }

    private function firmware(string $path): string
    {
        $firmware = collect(Firmware::all())->first(fn ($entry) => $entry->path === $path);
        if ($firmware === null) throw new RuntimeException('An EEPROM dependency is missing from the firmware store.');
        $ref = $this->put('firmware', $path, ['name' => $firmware->name, 'channel' => $firmware->channel], true);
        $this->file($ref, Firmware::basedir().'/'.$path, Firmware::basedir(), 'bin');
        return $ref;
    }

    private function file(string $ref, string $path, string $root, string $extension, ?string $expected = null): void
    {
        if (isset($this->sources[$ref])) return;
        $actual = realpath($path);
        $store = realpath($root);
        if (is_link($path) || !is_file($path) || $actual === false || $store === false || !str_starts_with($actual, $store.'/')) {
            throw new RuntimeException('A required file is missing or is outside its permitted store.');
        }
        $size = filesize($path);
        if ($size === false || $size < 1 || $size > self::MAX_ARCHIVE_BYTES) {
            throw new RuntimeException('A required file is empty, unreadable or exceeds the 64 GiB transfer limit.');
        }
        $sha = hash_file('sha256', $path);
        if ($sha === false
            || ($expected !== null && !hash_equals(strtolower($expected), $sha))) {
            throw new RuntimeException('A required file is too large, unreadable or differs from its recorded checksum.');
        }
        $this->objects[$ref]['file'] = ['member' => 'files/'.$ref.'.'.$extension, 'size' => $size, 'sha256' => $sha, 'included' => false];
        $this->sources[$ref] = $actual;
    }

    private function group(string $group, bool $secrets): array
    {
        if ($group === 'switches') {
            $refs = [];
            foreach (EthernetSwitch::orderBy('id')->get() as $switch) {
                $refs[] = $this->put('switch', $switch->id, Arr::only($switch->config(), self::FIELDS['switch']), $secrets);
            }
            return $refs;
        }
        if ($group === 'hosts') return Host::orderBy('id')->get()->map(fn ($host) => $this->put('host', $host->id, $this->fields($host, self::FIELDS['host']), true))->all();
        if ($group === 'notifications') {
            $refs = [];
            foreach (NotificationChannel::orderBy('id')->get() as $channel) {
                $data = $this->fields($channel, ['name', 'type', 'enabled', 'events']);
                $data['settings'] = Arr::only($channel->settings ?? [], self::NOTIFICATION_FIELDS);
                $refs[] = $this->put('notification', $channel->id, $data, $secrets);
            }
            return $refs;
        }
        if ($group === 'network') {
            $profiles = app(NetworkProfiles::class)->portable();
            if ($profiles === null) throw new RuntimeException('Ethernet/VLAN profiles could not be read from NetworkManager.');
            $refs = [];
            foreach ($profiles as $index => $profile) $refs[] = $this->put('network', $index, $profile, true);
            return $refs;
        }
        $values = match ($group) {
            'netbox' => NetBoxExport::settings(),
            'display' => ['fpms' => FpmsPanelSettings::get(), 'wallboard' => app(Wallboard::class)->settings(),
                'fpms_service' => app(Fpms::class)->settings(), 'hdmi_auto' => Setting::find('hdmi_color_mode')?->value === 'auto'],
            'usb_prep' => ['firmware' => app(UsbPrep::class)->firmware() ? $this->firmware(app(UsbPrep::class)->firmware()) : null,
                'settings' => app(UsbPrep::class)->settings()],
            'sensor_accounts' => ['accounts' => array_map(fn ($account) => Arr::only($account, ['name', 'user', 'password', 'enabled']), app(SavedAccounts::class)->definitions())],
            'mark_key' => ['key' => app(ModuleMark::class)->key()],
        };
        return [$this->put('settings', $group, ['group' => $group, 'values' => $values], $secrets)];
    }

    private function put(string $type, string|int $key, array $data, bool $secrets): string
    {
        $ref = $this->reference($type, $key);
        if (!isset($this->objects[$ref])) {
            $omitted = [];
            if (!$secrets) {
                foreach (self::secretPaths($type, $data) as $path) {
                    if (Arr::has($data, $path) && Arr::get($data, $path) !== null && Arr::get($data, $path) !== '') $omitted[] = $path;
                    Arr::forget($data, $path);
                }
            }
            $this->objects[$ref] = ['ref' => $ref, 'type' => $type, 'data' => $data, 'omitted_secrets' => $omitted];
        }
        return $ref;
    }

    private function reference(string $type, string|int $key): string
    {
        if (!isset($this->references[$type][$key])) $this->references[$type][$key] = $type.'-'.(count($this->references[$type] ?? []) + 1);
        return $this->references[$type][$key];
    }

    private function fields($model, array $fields): array
    {
        $result = [];
        foreach ($fields as $field) $result[$field] = $model->$field;
        return $result;
    }

    private function onlyKeys(array $data, array $allowed): void
    {
        if (array_diff(array_keys($data), $allowed)) $this->invalid();
    }

    private function shape(string $type, array $data): void
    {
        $name = 'required|string|max:255';
        $ref = fn ($kind) => ['present', 'nullable', 'string', 'regex:/^'.$kind.'-[1-9][0-9]{0,4}$/D'];
        $rules = match ($type) {
            'script' => ['name' => $name, 'script' => 'required|string', 'script_type' => 'required|in:preinstall,postinstall,firstboot', 'bg' => 'required|boolean', 'priority' => 'required|numeric'],
            'label' => ['name' => $name, 'printer_type' => 'required|in:ftp,command', 'template' => 'required|string', 'file_extension' => 'required|string|max:32|regex:/^[A-Za-z0-9]+$/D',
                'ftp_hostname' => 'nullable|string|max:255', 'ftp_username' => 'nullable|string|max:255', 'ftp_password' => 'nullable|string|max:255', 'print_command' => 'nullable|string|max:255'],
            'project' => ['name' => $name, 'image' => $ref('image'), 'label' => $ref('label'), 'firmware' => $ref('firmware'),
                'scripts' => 'present|array|max:2048', 'scripts.*' => ['required', 'string', 'distinct', 'regex:/^script-[1-9][0-9]{0,4}$/D'],
                'packages' => 'present|array|max:2048', 'packages.*' => ['required', 'string', 'distinct', 'regex:/^package-[1-9][0-9]{0,4}$/D'],
                'station_setup' => 'present|nullable|array'],
            'image' => ['filename' => $name, 'filename_extension' => 'required|in:gz,xz,bz2',
                'uncompressed_sha256' => ['nullable', 'string', 'regex:/^[0-9a-f]{64}$/D'],
                'uncompressed_size' => 'nullable|integer|min:1|max:'.\App\Services\ImageVerification::MAX_BYTES],
            'package' => ['filename' => $name, 'deb_package' => 'required|string|max:255|regex:/^[a-z0-9][a-z0-9+.-]*$/D',
                'deb_version' => 'required|string|max:255', 'deb_architecture' => 'required|string|max:64|regex:/^[a-z0-9][a-z0-9-]*$/D'],
            'firmware' => ['name' => 'required|string|max:255|regex:/^pieeprom-[A-Za-z0-9._-]+\.bin$/D', 'channel' => ['required', Rule::in(Firmware::CHANNELS)]],
            'settings' => ['group' => 'required|string', 'values' => 'present|array'],
            'host' => ['mac' => 'required|string|regex:/^([0-9a-fA-F]{2}:){5}[0-9a-fA-F]{2}$/D', 'ip' => 'required|ipv4', 'hostname' => 'nullable|string|max:63'],
            'switch' => ['host' => $name, 'version' => 'required|in:2c,3', 'write' => 'present|array:community,user,auth_protocol,auth_password,priv_protocol,priv_password'],
            'notification' => ['name' => $name, 'type' => ['required', Rule::in(array_keys(NotificationChannel::TYPES))],
                'enabled' => 'required|boolean', 'events' => 'required|array|min:1', 'events.*' => [Rule::in(array_keys(NotificationChannel::EVENTS))],
                'settings' => 'present|array:'.implode(',', self::NOTIFICATION_FIELDS)],
            'network' => ['name' => $name, 'device' => 'present|nullable|string|max:15', 'type' => 'required|in:ethernet,vlan',
                'vlan_id' => 'nullable|integer|between:1,4094', 'parent' => 'present|nullable|string|max:15', 'draft' => 'required|accepted',
                'ipv4' => 'present|array:'.implode(',', \App\Services\NetworkProfiles::PROPERTIES),
                'ipv6' => 'present|array:'.implode(',', \App\Services\NetworkProfiles::PROPERTIES)],
        };
        Validator::make($data, $rules)->validate();
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['manifest' => 'The configuration has an unsupported field, reference or file descriptor.']);
    }
}
