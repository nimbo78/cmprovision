<?php

namespace App\Services\Configuration;

use App\Models\EthernetSwitch;
use App\Models\Host;
use App\Models\Image;
use App\Models\Label;
use App\Models\NotificationChannel;
use App\Models\Package;
use App\Models\Project;
use App\Models\Script;
use App\Models\Setting;
use App\Services\Sensor\ModuleMark;
use App\Services\Sensor\SavedAccounts;
use App\Support\FpmsPanelSettings;
use Illuminate\Support\Facades\Crypt;

/** Database-only application, called inside the same transaction as the durable result. */
class TransferWriter
{
    public function apply(array $plans, array $assets): array
    {
        $objects = $drafts = $followups = [];
        $counts = ['created' => 0, 'reused' => 0, 'replaced' => 0, 'skipped' => 0];
        foreach ($plans as $ref => $plan) {
            $type = $plan['type'];
            $action = $plan['action'];
            $data = $plan['data'];
            $id = $plan['target'];
            if (!$plan['selected'] || $action === 'skip') { $counts['skipped']++; continue; }
            if ($action === 'reuse') {
                $counts['reused']++;
                $objects[$ref] = compact('type', 'action', 'id');
                continue;
            }
            if ($action === 'replace' && TransferState::protected($type, $id)) throw new \RuntimeException('A selected object is now in use. Review the import again.');
            if ($type === 'network') {
                $drafts[$ref] = ['data' => $data, 'interface' => $plan['interface']];
                $id = $ref;
                $followups['network'] = true;
            } elseif ($type === 'settings') {
                $this->settings($data, $objects, $followups);
                $id = $data['group'];
            } elseif ($type === 'firmware') $id = $assets[$ref]['filename'];
            else {
                $class = ['project' => Project::class, 'script' => Script::class, 'label' => Label::class,
                    'image' => Image::class, 'package' => Package::class, 'switch' => EthernetSwitch::class,
                    'host' => Host::class, 'notification' => NotificationChannel::class][$type];
                $model = $action === 'replace' ? $class::findOrFail($id) : new $class;
                if (in_array($type, ['project', 'script', 'label', 'notification'], true) && $action !== 'replace') {
                    $base = $data['name'];
                    $name = $base;
                    for ($n = 2; $class::where('name', $name)->exists(); $n++) $name = mb_substr($base, 0, 220).' ('.$n.')';
                    $data['name'] = $name;
                }
                if ($type === 'project') {
                    $attributes = $data;
                    foreach (['image', 'label', 'firmware', 'scripts', 'packages'] as $key) unset($attributes[$key]);
                    foreach (['image' => 'image_id', 'label' => 'label_id', 'firmware' => 'eeprom_firmware'] as $key => $field) {
                        $attributes[$field] = $data[$key] === null ? null : $objects[$data[$key]]['id'];
                    }
                    $model->forceFill($attributes)->save();
                    $scripts = [];
                    foreach ($data['scripts'] as $position => $script) $scripts[$objects[$script]['id']] = ['position' => $position];
                    $model->scripts()->sync($scripts);
                    $model->packages()->sync(array_map(fn ($ref) => $objects[$ref]['id'], $data['packages']));
                } elseif ($type === 'switch') {
                    $model->fillConfig($data)->save();
                } else {
                    if (in_array($type, ['image', 'package'], true)) {
                        $file = $assets[$ref];
                        $data['filename_on_server'] = $file['filename'];
                        $data['sha256'] = $file['facts']['sha256'];
                        if ($type === 'image') {
                            $data['uncompressed_sha256'] = $file['facts']['uncompressed_sha256'];
                            $data['uncompressed_size'] = $file['facts']['uncompressed_size'];
                            foreach (['download_state', 'download_error', 'download_url', 'source', 'source_type',
                                'download_bytes', 'download_total', 'download_started_at', 'download_updated_at',
                                'expected_sha256', 'expected_uncompressed_sha256', 'checksum_source', 'split_filename', 'split_offsets',
                                'split_piece_mb', 'split_error', 'split_queued_at'] as $key) $data[$key] = null;
                        } else $data['size'] = $file['facts']['size'];
                    }
                    $model->forceFill($data)->save();
                }
                $id = $model->getKey();
                if ($type === 'host') $followups['dhcp'] = true;
            }
            $counts[$action === 'replace' ? 'replaced' : 'created']++;
            $objects[$ref] = compact('type', 'action', 'id');
            if (isset($data['name'])) $objects[$ref]['name'] = $data['name'];
        }
        return ['result' => compact('objects', 'counts', 'followups'), 'drafts' => $drafts];
    }

    private function settings(array $data, array $objects, array &$followups): void
    {
        $values = $data['values'];
        $set = fn ($key, $value) => Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        switch ($data['group']) {
            case 'netbox':
                foreach ($values as $key => $value) $set('netbox_'.$key, $value);
                break;
            case 'mark_key':
                app(ModuleMark::class)->set($values['key']);
                break;
            case 'sensor_accounts':
                $accounts = array_map(fn ($account) => ['id' => bin2hex(random_bytes(8))] + $account, $values['accounts']);
                $set(SavedAccounts::SETTING, Crypt::encryptString(json_encode($accounts, JSON_THROW_ON_ERROR)));
                break;
            case 'usb_prep':
                $set('usb_prep_firmware', $objects[$values['firmware']]['id']);
                $set('usb_prep_settings', $values['settings']);
                // A new image must be built through the ordinary USB-prep settings flow.
                $set('usb_prep_sha256', '');
                $set('usb_prep_until', '');
                $followups['usb_prep'] = true;
                break;
            case 'display':
                FpmsPanelSettings::save($values['fpms']['theme'], $values['fpms']['ports_map'], $values['fpms']['color_mode']);
                $followups['display'] = ['wallboard' => $values['wallboard'], 'fpms_service' => $values['fpms_service'],
                    'hdmi_auto' => $values['hdmi_auto']];
                break;
        }
    }
}
