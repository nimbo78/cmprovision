<?php

namespace App\Services\Sensor;

use App\Models\{Cm,Cmlog,Project,ReprovisionOperation as Operation};
use App\Services\{ModuleOperationLock,NetworkChangeGate};
use Illuminate\Support\Facades\{Cache,Log};
use RuntimeException;

class Reprovision
{
    private const LOCK_SECONDS = 300;
    public function __construct(private ReprovisionTarget $targets, private SensorShell $shell,
        private ReprovisionAgent $agent, private Credentials $credentials, private SavedAccounts $accounts,
        private TargetInspection $inspection, private NetworkChangeGate $gate) {}

    public function blocker(Cm $cm): ?string
    {
        if (!preg_match('/^[a-f0-9]{16}$/Di', $cm->serial) || $cm->serial === str_repeat('0',16)) return 'The module has no valid hardware serial.';
        if ($cm->isRunning()) return 'The module is being provisioned or its first boot is being checked.';
        if (!(($project = Project::getActive()) && $project->image)) return 'Select an active project with an OS image first.';
        return null;
    }

    public function check(string $serial, int $user, string $session, ?string $id = null, ?array $account = null): Operation
    {
        $cm = Cm::where('serial', $serial)->firstOrFail();
        return $this->checkTarget($cm->mac, null, $user, $session, $id, $account);
    }

    public function checkTarget(?string $mac, ?string $address, int $user, string $session, ?string $id = null, ?array $account = null): Operation
    {
        $target = $this->targets->resolve($mac, $address);
        $known = Cm::where('mac', $target['mac'])->first();
        return ModuleOperationLock::run(ModuleOperationLock::targetKeys($known?->serial, $target['mac']), function () use ($target,$user,$session,$id,$account) {
            $cm = Cm::where('mac', $target['mac'])->first();
            if ($cm && ($why = $this->blocker($cm))) throw new RuntimeException($why);
            $project = $this->project();
            $pending = Operation::pendingTarget($target['mac'], $cm?->serial);
            if ($pending && $pending->updated_at->gt(now()->subSeconds(30)))
                throw new RuntimeException('A re-provision request is pending. Wait for the module to reboot before checking access again.');
            $op = $id ? $this->owned($id,$user,$session) : Operation::create([
                'id'=>bin2hex(random_bytes(16)), 'serial'=>$cm?->serial, 'user_id'=>$user, 'session_hash'=>hash('sha256',$session),
                'project_id'=>$project->id, 'project_hash'=>ReprovisionIntent::fingerprint($project),
                'address'=>$target['address'], 'mac'=>$target['mac'], 'run_started_at'=>$cm?->provisioning_started_at?->toISOString(),
                'context'=>['target'=>$target,'cm_id'=>$cm?->id], 'expires_at'=>now()->addSeconds(120), 'supersedes_id'=>$pending?->id,
            ]);
            if (!in_array($op->state,['checking','account','password_expired','checked'],true)) return $op;
            $this->unchanged($op,$project,$target);
            $op->update(['state'=>'checking','identity'=>null,'method'=>null]);
            try {
                $r = $this->shell->key($op->address,$op->id,'inspect');
                if ($r->status === 'error' && str_contains($r->output,'refused: unknown command') && $cm)
                    $r = $this->shell->key($op->address,$op->id,'identify');
                $identity = $this->agent->identity($r);
                $legacyUnknown = $r->status === 'ok' && $identity && $identity['agent'] === '1' && !$cm;
                if (in_array($r->status,['denied','password_expired','no_sudo'],true) || $legacyUnknown
                    || ($r->status === 'error' && str_contains($r->output,'refused: unknown command'))) {
                    $account ??= $this->credentials->get($op->id);
                    if (!$account) {
                        if ($id === null && Cache::add('reprovision-auto:'.$op->mac,true,60)) {
                            foreach ($this->accounts->enabled() as $candidate) {
                                $result = $this->inspectAccount($cm,$op,$candidate);
                                if (in_array($result->status,['denied','no_sudo'],true)) continue;
                                if (!in_array($result->status,['ok','password_expired'],true))
                                    return $this->state($op,'account',$this->error($result));
                                return $this->acceptAccount($op,['user'=>$candidate['user'],'password'=>$candidate['password']],$result,true);
                            }
                        }
                        return $this->state($op,'account','The provisioner key and saved accounts did not provide full inspection access. Enter the sensor account. Automatic checks run at most once per minute.');
                    }
                    $result = $this->inspectAccount($cm,$op,$account);
                    if (in_array($result->status,['ok','password_expired'],true)) return $this->acceptAccount($op,$account,$result);
                    $this->credentials->forget($op->id);
                    return $this->state($op,'account',$this->error($result));
                }
                if ($r->status !== 'ok') return $this->state($op,'account',$this->error($r));
                if (!$identity) throw new RuntimeException('The sensor did not return a supported disk identity (eMMC/SD, ext4 root on partition 2).');
                $this->credentials->forget($op->id);
                return $this->acceptIdentity($op,$identity,'key');
            } catch (\InvalidArgumentException|RuntimeException $e) {
                $this->credentials->forget($op->id); $this->shell->forget($op->id);
                return $this->state($op,'failed',$e->getMessage());
            }
        }, self::LOCK_SECONDS);
    }

    private function inspectAccount(?Cm $cm, Operation $op, array $account): ShellResult
    {
        $this->validateAccount($account);
        $request = $cm ? 'identify' : 'inspect';
        $r = $this->shell->account($op->address,$op->id,$account['user'],$account['password'],
            $this->agent->command($request,$cm?->provisioning_storage,!$cm));
        if ($r->status !== 'ok') return $r;
        $identity = $this->agent->identity($r);
        if (!$identity) throw new RuntimeException('The sensor did not return a supported disk identity (eMMC/SD, ext4 root on partition 2).');
        $this->inspection->validate($identity,$op->mac,$cm);
        if (!$cm) return $r; // The first-time inspector already ran through sudo.
        $sudo = $this->shell->account($op->address,$op->id,$account['user'],$account['password'],"sudo -k -n true || sudo -k -S -p '' true");
        if (in_array($sudo->status,['host_changed','unreachable'],true)) return $sudo;
        return $sudo->status === 'ok' ? $r : new ShellResult('no_sudo');
    }

    private function acceptAccount(Operation $op, array $account, ShellResult $r, bool $saved = false): Operation
    {
        $op->fill(['username'=>$account['user'],'expires_at'=>now()->addSeconds(120)]);
        if ($r->status === 'password_expired') {
            $this->credentials->put($op->id,$account);
            return $this->state($op,'password_expired','The sensor account '.$account['user'].' asks for a new password.');
        }
        $checked = $this->acceptIdentity($op,$this->agent->identity($r),'account',$saved);
        $this->credentials->put($op->id,$account); // Match the checked ticket's full lifetime after slow network validation.
        return $checked;
    }

    private function acceptIdentity(Operation $op, array $identity, string $method, bool $saved = false): Operation
    {
        $accept = function () use ($op,$identity,$method,$saved) {
            $cm = $this->record($op);
            $this->inspection->validate($identity,$op->mac,$cm);
            if (($other = Cm::where('serial',$identity['serial'])->first()) && $other->mac !== $op->mac)
                throw new RuntimeException('This hardware serial belongs to a different recorded MAC. Resolve the identity conflict before re-provisioning.');
            if ($op->serial !== null && $op->serial !== $identity['serial']) throw new RuntimeException('The hardware serial changed. Check access again.');
            if (Operation::pendingTarget($op->mac,$identity['serial'])?->id !== $op->supersedes_id)
                throw new RuntimeException('Another re-provision request is pending.');
            $project = $this->project();
            if ($project->storage !== $identity['storage'])
                throw new RuntimeException('The active project destination does not match the inspected running root disk.');
            if ((int) $project->image->uncompressed_size > (int) $identity['sectors'] * 512)
                throw new RuntimeException('The active project image does not fit this disk.');
            $this->unchanged($op,$project,$this->targets->resolve($op->mac,$op->address));
            $op->fill(['serial'=>$identity['serial'],'method'=>$method,'identity'=>$identity,'expires_at'=>now()->addSeconds(120)]);
            return $this->state($op,'checked',$saved ? 'Access checked using a saved sensor account. Confirm the disk and project before re-provisioning.'
                : 'Access checked. Confirm the disk and project before re-provisioning.');
        };
        // Unknown serials are learned while holding the normalized MAC lock; serial locks always follow it.
        return $op->serial === null ? ModuleOperationLock::run([$identity['serial']],$accept,self::LOCK_SECONDS) : $accept();
    }

    public function changePassword(string $id, int $user, string $session, string $new, string $repeat): Operation
    {
        $op = $this->owned($id,$user,$session);
        if ($op->state !== 'password_expired') throw new RuntimeException('Check access before changing the sensor password.');
        $account = $this->credentials->get($id);
        if (!$account) throw new RuntimeException('The credentials expired. Check access again.');
        $this->validateAccount(['user'=>$account['user'],'password'=>$new]);
        if (!hash_equals($new,$repeat)) throw new RuntimeException('The new passwords do not match.');
        ModuleOperationLock::run(ModuleOperationLock::targetKeys($op->serial,$op->mac),function () use ($op,$account,$new) {
            $this->unchanged($op,$this->project(),$this->targets->resolve($op->mac,$op->address));
            $r = $this->shell->changePassword($op->address,$op->id,$account['user'],$account['password'],$new);
            if ($r->status !== 'ok') throw new RuntimeException('The sensor did not accept the password change. '.$r->output);
            $this->credentials->put($op->id,['user'=>$account['user'],'password'=>$new]);
            $op->update(['password_changed'=>true,'expires_at'=>now()->addSeconds(120)]);
        }, self::LOCK_SECONDS);
        return $this->checkTarget($op->mac,$op->address,$user,$session,$id);
    }

    public function wipe(string $id, int $user, string $session, string $operator): Operation
    {
        $op = $this->owned($id,$user,$session,false);
        $result = $this->gate->start(fn () => ModuleOperationLock::run(ModuleOperationLock::targetKeys($op->serial,$op->mac),function () use ($op,$user,$session,$operator) {
            $op = $this->owned($op->id,$user,$session,false);
            if ($op->state !== 'checked') return $op;
            if ($op->expires_at->isPast()) throw new RuntimeException('The access check expired. Check access again.');
            $this->unchanged($op,$this->project(),$this->targets->resolve($op->mac,$op->address));
            $pending = Operation::pendingTarget($op->mac,$op->serial);
            if ($pending && $pending->id !== $op->supersedes_id) throw new RuntimeException('Another re-provision request is already pending.');
            $account = $this->credentials->get($op->id);
            if ($op->method === 'account' && !$account) throw new RuntimeException('The credentials expired. Check access again.');
            $r = $this->execute($op,$op->identity['agent'] === '2' ? 'inspect' : 'identify',$account);
            $identity = $this->agent->identity($r);
            if (!$identity || $identity !== $op->identity) throw new RuntimeException('The sensor identity or disk changed. Check access again.');
            $cm = $this->record($op); $this->inspection->validate($identity,$op->mac,$cm);
            $this->unchanged($op,$this->project(),$this->targets->resolve($op->mac,$op->address));
            if ($op->expires_at->isPast()) throw new RuntimeException('The access check expired during reinspection. Check access again.');
            if ($pending) $pending->update(['state'=>'superseded']);
            // Durable intent precedes one destructive dispatch; a dead PHP process leaves an unknown guarded result.
            $op->update(['state'=>'dispatching','expires_at'=>now()->addMinutes(30),'message'=>'Sending the re-provision command.']);
            $cm?->update(['durable_bytes'=>null,'durable_at'=>null,'durable_image_id'=>null,'progress_base'=>null]);
            $this->log($cm,$op,'Re-provision requested by '.$operator.' over '.($op->method === 'key' ? "the provisioner's key" : 'account '.$op->username).($op->password_changed ? ' (password changed)' : '').'.');
            try {
                $r = $this->execute($op,'wipe '.$identity['serial'].' '.$identity['cid'].' '.$identity['sectors'],$account);
                if ($r->acknowledges($identity['serial'])) {
                    $this->log($cm,$op,'Re-provision by '.$operator.': wiped over '.($op->method === 'key' ? "the provisioner's key" : 'account '.$op->username).'.');
                    return $this->state($op,'waiting','Wiped: waiting for the sensor to return to network boot.');
                }
                return $this->state($op,'unknown','The command was sent, but its result is unknown. It will not be repeated automatically. Wait for network boot or check access again.');
            } catch (\Throwable $e) {
                return $this->state($op,'unknown','The SSH connection ended after dispatch. The command will not be repeated automatically.');
            } finally { $this->credentials->forget($op->id); $this->shell->forget($op->id); }
        }, self::LOCK_SECONDS));
        if (!$result instanceof Operation) throw new RuntimeException('Another station operation is pending. Finish it before re-provisioning.');
        return $result;
    }

    public function confirmBoot(string $serial, string $operator): void
    {
        $pending = Operation::pending($serial);
        if (!$pending) throw new RuntimeException('No re-provision request is pending.');
        ModuleOperationLock::run(ModuleOperationLock::targetKeys($serial,$pending->mac),function () use ($pending,$operator) {
            app(ReprovisionStation::class)->requireReady();
            $cm = Cm::where('serial',$pending->serial)->first();
            if ($cm?->isRunning()) throw new RuntimeException('The module is already being provisioned.');
            $project = $this->project();
            $pending->update(['project_id'=>$project->id,'project_hash'=>ReprovisionIntent::fingerprint($project),'expires_at'=>now()->addMinutes(30),
                'message'=>'Current project confirmed. Restart the module if it is waiting in the utility OS.']);
            $this->log($cm,$pending,'Re-provision: '.$operator.' confirmed '.$project->name.' for the next network boot.');
        });
    }

    public function cancel(string $id, int $user, string $session): void
    {
        $op = $this->owned($id,$user,$session,false);
        ModuleOperationLock::run(ModuleOperationLock::targetKeys($op->serial,$op->mac),function () use ($op) {
            $op->refresh();
            if (in_array($op->state,['checking','account','password_expired','checked'],true)) $op->update(['state'=>'cancelled']);
            $this->credentials->forget($op->id); $this->shell->forget($op->id);
        });
    }

    private function execute(Operation $op, string $request, ?array $account): ShellResult
    {
        return $op->method === 'key' ? $this->shell->key($op->address,$op->id,$request)
            : $this->shell->account($op->address,$op->id,$account['user'],$account['password'],
                $this->agent->command($request,$op->identity['storage'],str_starts_with($request,'wipe ') || $request === 'inspect'));
    }
    private function owned(string $id, int $user, string $session, bool $fresh = true): Operation
    {
        $op = Operation::findOrFail($id);
        if ($op->user_id !== $user || !hash_equals($op->session_hash,hash('sha256',$session))) abort(403);
        if ($fresh && $op->expires_at->isPast()) { $this->credentials->forget($id); $this->shell->forget($id); throw new RuntimeException('The access check expired. Check access again.'); }
        return $op;
    }
    private function project(): Project
    {
        $p = Project::getActive();
        if (!$p?->image) throw new RuntimeException('Select an active project with an OS image first.');
        if ($why = $p->image->notReadyReason()) throw new RuntimeException($why);
        if ($p->verify && !$p->image->uncompressed_sha256) throw new RuntimeException('Wait for the uncompressed image checksum before re-provisioning.');
        return $p;
    }
    private function record(Operation $op): ?Cm
    {
        $cm = Cm::where('mac',$op->mac)->first();
        if (($op->context['cm_id'] ?? null) !== $cm?->id) throw new RuntimeException('The module registration changed. Check access again.');
        return $cm;
    }
    private function unchanged(Operation $op, Project $project, array $target): void
    {
        $cm = $this->record($op);
        if ($cm && ($why = $this->blocker($cm))) throw new RuntimeException($why);
        if ($op->address !== $target['address'] || $op->mac !== $target['mac'] || ($op->context['target'] ?? null) !== $target
            || ($cm && $op->serial !== $cm->serial) || $op->run_started_at !== $cm?->provisioning_started_at?->toISOString()
            || $op->project_id != $project->id || !hash_equals($op->project_hash,ReprovisionIntent::fingerprint($project)))
            throw new RuntimeException('The module, address or project changed, or the current stand configuration changed. Close this dialog and check access again.');
    }
    private function validateAccount(array $account): void
    {
        if (!is_string($account['user'] ?? null) || !preg_match('/^[a-z_][a-z0-9_-]{0,31}$/D',$account['user'])
            || !is_string($account['password'] ?? null) || strlen($account['password']) < 1 || strlen($account['password']) > 1024
            || preg_match('/[\r\n\x00]/',$account['password'])) throw new RuntimeException('Enter a valid sensor login and password without line breaks.');
    }
    private function state(Operation $op, string $state, string $message): Operation
    {
        $op->fill(['state'=>$state,'message'=>$message])->save(); return $op;
    }
    private function error(ShellResult $r): string
    {
        return match ($r->status) {
            'denied'=>'The sensor did not accept this account password.',
            'no_sudo'=>'The sensor account could not obtain sudo access.',
            'unreachable'=>'The sensor does not answer SSH. It may be off, booting or still in the utility OS; use PoE restart for the utility OS.',
            'host_changed'=>'The SSH host key changed during this operation. Close the dialog and check access again.',
            default=>'The sensor could not be checked. '.$r->output,
        };
    }
    private function log(?Cm $cm, Operation $op, string $message): void
    {
        if ($cm) Cmlog::create(['cm'=>$cm->mac,'board'=>$cm->provisioning_board,'loglevel'=>'info','msg'=>$message]);
        else Log::notice($message,['operation'=>$op->id,'mac'=>$op->mac,'serial'=>$op->serial]);
    }
}
