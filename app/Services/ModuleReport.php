<?php
namespace App\Services;

use App\Models\Cm;
use App\Models\UsbDiagnosticReport;
use App\Support\AppVersion;
use App\Support\ModuleDiagnostics;
use App\Support\ModuleHardware;
use App\Support\RunTimeline;

/** Shareable recorded evidence, deliberately excluding scripts, credentials and arbitrary logs. */
class ModuleReport
{
    private function text($value, int $limit = 160): ?string
    {
        if (!is_scalar($value) || (string) $value === '') return null;
        return mb_substr(preg_replace('/[\x00-\x1f\x7f]/u', ' ', mb_scrub((string) $value, 'UTF-8')), 0, $limit);
    }

    private function base(string $kind, array $identity, array $hardware): array
    {
        return ['schema'=>1, 'kind'=>$kind, 'generated_at'=>now()->toIso8601String(),
            'station'=>['name'=>gethostname() ?: 'Unknown', 'version'=>AppVersion::text()],
            'identity'=>$identity, 'hardware'=>$hardware, 'run'=>null, 'eeprom'=>null, 'location'=>null, 'diagnostics'=>[],
            'limitations'=>['Recorded observations only; no live hardware or switch poll.',
                'Inferred SKU and firmware-reported attributes do not prove authenticity.',
                'This is the latest recorded state, not an immutable archive of every past run.']];
    }

    private function observation(array $report, string $channel, $received): array
    {
        $facts = [];
        foreach (ModuleDiagnostics::FACTS as $key) {
            if (($value = $this->text($report['facts'][$key] ?? null)) !== null) $facts[$key] = $value;
        }
        return ['channel'=>$channel, 'received_at'=>$received?->toIso8601String(),
            'context'=>in_array($report['context'] ?? null, ModuleDiagnostics::CONTEXTS, true) ? $report['context'] : 'unknown',
            'facts'=>$facts];
    }

    public function forModule(Cm $cm): array
    {
        $data = $this->base('module', ['serial'=>$this->text($cm->serial), 'mac'=>$this->text($cm->mac),
            'hostname'=>$this->text($cm->hostname)], ModuleHardware::forModule($cm));
        $timeline = [];
        foreach (array_slice(is_array($cm->timeline) ? $cm->timeline : [], 0, Cm::MAX_TIMELINE) as $entry) {
            if (!is_array($entry) || !array_key_exists($entry['phase'] ?? '', Cm::PHASE_LABELS)
                || !is_int($entry['at'] ?? null) || $entry['at'] < 0 || $entry['at'] > 253402300799) continue;
            $timeline[] = ['phase'=>$entry['phase'], 'detail'=>null, 'at'=>$entry['at']];
        }
        $copy = clone $cm; $copy->timeline = $timeline;
        $steps = array_map(fn ($step) => ['phase'=>$step['phase'], 'label'=>$step['label'], 'at'=>$step['at']->toIso8601String(),
            'seconds'=>$step['seconds'], 'bytes_per_second'=>$step['speed']], RunTimeline::steps($copy));
        $data['run'] = ['operation'=>$cm->usb_prep_at !== null ? 'usb-prep' : 'provisioning',
            'image_provisioned'=>$cm->usb_prep_at !== null ? false : null,
            'result'=>array_key_exists((string) $cm->phase, Cm::PHASE_LABELS) ? $cm->phase : 'not-recorded',
            'started_at'=>$cm->provisioning_started_at?->toIso8601String(), 'completed_at'=>$cm->provisioning_complete_at?->toIso8601String(),
            'project'=>$this->text($cm->project?->name), 'image'=>$this->text($cm->image_filename, 255),
            'image_sha256'=>preg_match('/^[0-9a-f]{64}$/iD', (string) $cm->image_sha256) ? strtolower($cm->image_sha256) : null,
            'os_check'=>$this->text($cm->os_check), 'steps'=>$steps];
        $data['eeprom'] = ['before'=>$cm->eepromVersionBefore(), 'after'=>$cm->eepromVersionAfter(),
            'result'=>$this->text($cm->eeprom_result)];
        $data['location'] = ['switch'=>$this->text($cm->switch_name), 'port'=>$this->text($cm->switch_port)];
        if (is_array($cm->storage_diagnostics))
            $data['diagnostics'][] = $this->observation($cm->storage_diagnostics, 'module-report', $cm->storage_diagnostics_at);
        $usb = UsbDiagnosticReport::where('serial', $cm->serial)->orderByDesc('received_at')->first();
        if ($usb) $data['diagnostics'][] = $this->observation($usb->report, 'usb', $usb->received_at);
        return $data;
    }

    public function forUsb(UsbDiagnosticReport $usb): array
    {
        $data = $this->base('usb-observation', ['serial'=>$this->text($usb->serial), 'mac'=>$this->text($usb->mac), 'hostname'=>null],
            ModuleHardware::fromFacts($usb->report['facts'] ?? []));
        $data['diagnostics'][] = $this->observation($usb->report, 'usb', $usb->received_at);
        $data['limitations'][] = 'A USB observation alone does not prove registration or successful provisioning; it may describe another boot.';
        return $data;
    }
}
