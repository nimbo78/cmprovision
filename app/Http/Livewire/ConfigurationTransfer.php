<?php

namespace App\Http\Livewire;

use App\Models\ConfigurationTransferOperation;
use App\Services\Configuration\TransferAssets;
use App\Services\Configuration\TransferCatalog;
use App\Services\Configuration\TransferImporter;
use App\Services\Configuration\TransferPassword;
use App\Services\Configuration\TransferRequests;
use App\Services\Configuration\TransferWorkspace;
use Laravel\Jetstream\ConfirmsPasswords;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Configuration transfer')]
class ConfigurationTransfer extends Component
{
    use ConfirmsPasswords;

    public $selection = ['projects' => [], 'scripts' => [], 'labels' => [], 'groups' => [], 'binaries' => []];
    public $format = 'json', $includeSecrets = false, $protectExport = false;
    public $choices = ['objects' => [], 'interfaces' => []];
    #[Locked] public $operationId = null;
    #[Locked] public $status = null;
    #[Locked] public $preview = null;
    #[Locked] public $report = null;
    #[Locked] public $copyText = '';
    #[Locked] public $previewDirty = false;
    #[Locked] public $choiceBaseline = ['objects' => [], 'interfaces' => []];
    public $notice = null;

    public function mount(): void
    {
        foreach (['project' => 'projects', 'script' => 'scripts', 'label' => 'labels'] as $query => $group) {
            $id = request()->query($query);
            if (is_string($id) && ctype_digit($id)) $this->selection[$group] = [(int) $id];
        }
        if (request()->query('operation')) $this->openOperation((string) request()->query('operation'));
    }

    public function render()
    {
        return view('livewire.configuration-transfer', [
            'catalog' => app(TransferCatalog::class)->choices(),
            'masterConfigured' => app(TransferPassword::class)->configured(),
            'operation' => $this->operationId ? $this->owned() : null,
        ]);
    }

    public function saveMasterPassword(#[\SensitiveParameter] string $password): void
    {
        $this->ensurePasswordIsConfirmed();
        app(TransferPassword::class)->save($password);
        $this->dispatch('master-password-saved');
        $this->notice = 'Master password saved on this station.';
    }

    public function forgetMasterPassword(): void
    {
        $this->ensurePasswordIsConfirmed();
        app(TransferPassword::class)->forget();
        $this->notice = 'Saved master password removed.';
    }

    public function startExport(#[\SensitiveParameter] ?string $password = null): void
    {
        $this->ensurePasswordIsConfirmed();
        $this->attempt(function () use ($password) {
            $row = app(TransferRequests::class)->export(auth()->id(), session()->getId(), $this->selection,
                $this->format, (bool) $this->includeSecrets, (bool) $this->protectExport, $password);
            $this->openOperation($row->id);
            $this->dispatch('transfer-queued');
        });
    }

    public function startTextImport(string $text, #[\SensitiveParameter] ?string $password = null): void
    {
        $this->attempt(function () use ($text, $password) {
            $row = app(TransferRequests::class)->text(auth()->id(), session()->getId(), $text, $password);
            $this->openOperation($row->id);
        });
    }

    public function openOperation(string $id): void
    {
        app(TransferWorkspace::class)->owned($id, auth()->id(), session()->getId());
        $this->operationId = $id;
        $this->reset('preview', 'report', 'choices', 'copyText', 'notice');
        $this->refreshOperation();
    }

    public function refreshOperation(): void
    {
        if ($this->operationId === null) return;
        $row = $this->owned();
        $this->status = $row->status;
        if ($row->status === 'previewed') {
            $this->preview = $row->options['public_preview'] ?? null;
            $this->choices = ['objects' => [], 'interfaces' => []];
            foreach ($this->preview['objects'] ?? [] as $object) {
                $this->choices['objects'][$object['ref']] = ['action' => $object['action'], 'target' => $object['target'], 'selected' => $object['selected']];
                if ($object['interface']) $this->choices['interfaces'][$object['ref']] = $object['interface'];
            }
            $this->previewDirty = false;
            $this->choiceBaseline = $this->choices;
        } elseif ($row->status === 'applied') $this->report = $row->result;
    }

    public function review(#[\SensitiveParameter] array $secrets = []): void
    {
        $this->attempt(function () use ($secrets) {
            $row = $this->owned();
            app(TransferWorkspace::class)->locked($row, function () use ($row, $secrets) {
                abort_unless(in_array($row->status, ['prepared', 'previewed'], true), 409);
                $changes = ['objects' => [], 'interfaces' => []];
                foreach ($this->choices['objects'] ?? [] as $ref => $values) {
                    foreach ($values as $key => $value) {
                        if ($value !== ($this->choiceBaseline['objects'][$ref][$key] ?? null)) $changes['objects'][$ref][$key] = $value;
                    }
                }
                foreach ($this->choices['interfaces'] ?? [] as $ref => $values) {
                    if ($values !== ($this->choiceBaseline['interfaces'][$ref] ?? null)) $changes['interfaces'][$ref] = $values;
                }
                $row->update(['status' => 'reviewing', 'options' => array_replace($row->options,
                    ['review_choices' => array_replace($changes, ['secrets' => $secrets])])]);
                \App\Jobs\ReviewConfigurationTransfer::dispatch($row->id);
            });
            $this->status = 'reviewing';
        });
    }

    public function applyImport(): void
    {
        $this->ensurePasswordIsConfirmed();
        $this->attempt(function () {
            if ($this->previewDirty) throw new \RuntimeException('Update the preview after changing your choices.');
            $row = $this->owned();
            app(TransferWorkspace::class)->locked($row, function () use ($row) {
                if ($row->status === 'applied' || $row->status === 'applying') return;
                abort_unless($row->status === 'previewed', 409);
                $row->update(['status' => 'applying', 'options' => array_replace($row->options, ['apply_fingerprint' => $this->preview['fingerprint'] ?? ''])]);
                \App\Jobs\ApplyConfigurationTransfer::dispatch($row->id);
            });
            $this->refreshOperation();
            $this->copyText = '';
        });
    }

    public function updatedChoices(): void { $this->previewDirty = true; }

    public function showText(): void
    {
        $this->ensurePasswordIsConfirmed();
        $row = $this->owned();
        abort_unless($row->direction === 'export' && $row->status === 'ready' && $row->format === 'json', 409);
        $this->copyText = file_get_contents(app(TransferWorkspace::class)->path($row, $row->result['member']));
    }

    public function cancelTransfer(): void
    {
        $row = $this->owned();
        app(TransferWorkspace::class)->locked($row, function () use ($row) {
            if ($row->status === 'applied') return;
            app(TransferAssets::class)->cleanup($row);
            $row->update(['status' => 'cancelled', 'options' => null, 'secrets' => null, 'expires_at' => now()->subSecond()]);
        });
        $this->reset('operationId', 'status', 'preview', 'report', 'copyText', 'choices');
        app(TransferWorkspace::class)->prune();
    }

    public function openNetworkDraft(string $ref)
    {
        $row = $this->owned();
        abort_unless($row->status === 'applied' && isset($row->options['drafts'][$ref]), 404);
        session()->put('configuration_transfer_draft', ['operation' => $row->id, 'ref' => $ref]);
        return redirect()->route('settings', ['tab' => 'network'])->withFragment('network-profiles');
    }

    public function applyDisplayPreferences(): void
    {
        $this->ensurePasswordIsConfirmed();
        $this->attempt(function () {
            $row = $this->owned();
            app(TransferWorkspace::class)->locked($row, function () use ($row) {
                abort_unless($row->status === 'applied', 409);
                $result = $row->result;
                $settings = $result['followups']['display'] ?? null;
                if ($settings === null) return;
                app(\App\Services\Wallboard::class)->save($settings['wallboard']);
                app(\App\Services\Fpms::class)->save($settings['fpms_service']);
                \App\Models\Setting::updateOrCreate(['key' => 'hdmi_color_mode'], ['value' => $settings['hdmi_auto'] ? 'auto' : 'manual']);
                unset($result['followups']['display']);
                $row->update(['result' => $result]);
                $this->report = $result;
                $this->notice = 'Imported HDMI and front-panel service preferences applied.';
            });
        });
    }

    public function rebuildUsbPrep(): void
    {
        $this->ensurePasswordIsConfirmed();
        $this->attempt(function () {
            $row = $this->owned();
            app(TransferWorkspace::class)->locked($row, function () use ($row) {
                abort_unless($row->status === 'applied' && !empty($row->result['followups']['usb_prep']), 409);
                if (\App\Models\Cm::whereIn('phase', \App\Models\Cm::ACTIVE_PHASES)->exists()) throw new \RuntimeException('Wait until running devices finish before rebuilding USB preparation.');
                app(\App\Services\UsbPrep::class)->build();
                $result = $row->result;
                unset($result['followups']['usb_prep']);
                $row->update(['result' => $result]);
                $this->report = $result;
            });
        });
    }

    public function applyDhcpReservations(): void
    {
        $this->ensurePasswordIsConfirmed();
        $this->attempt(function () {
            $row = $this->owned();
            app(TransferWorkspace::class)->locked($row, function () use ($row) {
                abort_unless($row->status === 'applied' && !empty($row->result['followups']['dhcp']), 409);
                if (\App\Models\Cm::whereIn('phase', \App\Models\Cm::ACTIVE_PHASES)->exists()) throw new \RuntimeException('Wait until running devices finish before restarting DHCP.');
                $dhcp = app(\App\Services\DhcpReservations::class);
                $dhcp->write();
                if (!$dhcp->restart()) throw new \RuntimeException('DHCP configuration was saved, but the service could not restart. Retry or check Service status.');
                $result = $row->result;
                unset($result['followups']['dhcp']);
                $row->update(['result' => $result]);
                $this->report = $result;
            });
        });
    }

    private function owned(): ConfigurationTransferOperation
    {
        return app(TransferWorkspace::class)->owned($this->operationId, auth()->id(), session()->getId());
    }

    private function attempt(callable $action): void
    {
        $this->resetErrorBag();
        $this->notice = null;
        try { $action(); }
        catch (\Illuminate\Validation\ValidationException $error) { throw $error; }
        catch (\RuntimeException $error) { $this->addError('transfer', $error->getMessage()); }
    }
}
