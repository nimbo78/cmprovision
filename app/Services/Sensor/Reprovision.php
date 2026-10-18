<?php

namespace App\Services\Sensor;

use App\Models\Cm;
use App\Models\Cmlog;
use App\Models\Project;
use App\Models\ReprovisionOperation as Operation;
use App\Services\ModuleOperationLock;
use App\Services\SensorAddress;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class Reprovision
{
    public function __construct(private SensorAddress $addresses, private SensorShell $shell,
                                private ReprovisionAgent $agent, private Credentials $credentials, private SavedAccounts $accounts) {}

    public function blocker(Cm $cm): ?string
    {
        if (!preg_match('/^[a-f0-9]{16}$/Di', $cm->serial) || $cm->serial === str_repeat('0', 16)) return 'The module has no valid hardware serial.';
        if ($cm->isRunning()) return 'The module is being provisioned or its first boot is being checked.';
        if (!$this->addresses->find($cm)) return 'No unambiguous current DHCP lease on the stand network.';
        if (!(($project = Project::getActive()) && $project->image)) return 'Select an active project with an OS image first.';
        return null;
    }

    public function check(string $serial, int $user, string $session, ?string $id = null, ?array $account = null): Operation
    {
        return ModuleOperationLock::run([$serial], function () use ($serial, $user, $session, $id, $account) {
            $cm = Cm::where('serial', $serial)->firstOrFail();
            if ($why = $this->blocker($cm)) throw new RuntimeException($why);
            $address = $this->addresses->find($cm);
            $project = Project::getActive();
            $pending = Operation::pending($serial);
            if ($pending && $pending->updated_at->gt(now()->subSeconds(30)))
                throw new RuntimeException('A re-provision request is pending. Wait for the module to reboot before checking access again.');
            $op = $id ? $this->owned($id, $user, $session) : Operation::create([
                'id' => bin2hex(random_bytes(16)), 'serial' => $serial, 'user_id' => $user, 'session_hash' => hash('sha256', $session),
                'project_id' => $project->id, 'project_hash' => ReprovisionIntent::fingerprint($project),
                'address' => $address['address'], 'mac' => $address['mac'], 'run_started_at' => $cm->provisioning_started_at?->toISOString(),
                'expires_at' => now()->addMinutes(2), 'supersedes_id' => $pending?->id,
            ]);
            if (!in_array($op->state, ['checking','account','password_expired','checked'], true)) return $op;
            $this->unchanged($op, $cm, $project, $address);
            $op->update(['state' => 'checking', 'identity' => null, 'method' => null]);
            try {
                $r = $this->shell->key($op->address, $op->id, 'identify');
                $method = 'key';
                if (in_array($r->status, ['denied', 'password_expired'], true)) {
                    $account ??= $this->credentials->get($op->id);
                    if (!$account) {
                        if ($id === null && Cache::add('reprovision-auto:'.$cm->serial, true, 60)) {
                            foreach ($this->accounts->enabled() as $candidate) {
                                $result = $this->inspectAccount($cm, $op, $candidate);
                                if (in_array($result->status, ['denied', 'no_sudo'], true)) continue;
                                if (!in_array($result->status, ['ok', 'password_expired'], true))
                                    return $this->state($op, 'account', $this->error($result));
                                $account = ['user' => $candidate['user'], 'password' => $candidate['password']];
                                return $this->acceptAccount($op, $account, $result, true);
                            }
                        }
                        return $this->state($op, 'account', 'The provisioner key and saved accounts did not provide access. Enter the sensor account. Automatic checks run at most once per minute.');
                    }
                    $this->validateAccount($account);
                    $result = $this->inspectAccount($cm, $op, $account);
                    if (in_array($result->status, ['ok', 'password_expired'], true)) return $this->acceptAccount($op, $account, $result);
                    $this->credentials->forget($op->id);
                    return $this->state($op, 'account', $this->error($result));
                }
                if ($r->status !== 'ok') return $this->state($op, 'account', $this->error($r));
                $identity = $this->agent->identity($r);
                if (!$identity) throw new RuntimeException('The sensor did not return a supported disk identity (eMMC/SD, ext4 root on partition 2).');
                $this->matchIdentity($cm, $identity);
                $this->credentials->forget($op->id);
                $op->fill(['method' => $method, 'identity' => $identity, 'expires_at' => now()->addMinutes(2)]);
                return $this->state($op, 'checked', 'Access checked. Confirm the disk and project before re-provisioning.');
            } catch (\InvalidArgumentException|RuntimeException $e) {
                $this->credentials->forget($op->id);
                return $this->state($op, 'failed', $e->getMessage());
            }
        });
    }

    private function inspectAccount(Cm $cm, Operation $op, array $account): ShellResult
    {
        $this->validateAccount($account);
        $r = $this->shell->account($op->address, $op->id, $account['user'], $account['password'],
            $this->agent->command('identify', $cm->provisioning_storage));
        if ($r->status !== 'ok') return $r;
        $identity = $this->agent->identity($r);
        if (!$identity) throw new RuntimeException('The sensor did not return a supported disk identity (eMMC/SD, ext4 root on partition 2).');
        $this->matchIdentity($cm, $identity);
        $sudo = $this->shell->account($op->address, $op->id, $account['user'], $account['password'], "sudo -k -n true || sudo -k -S -p '' true");
        if (in_array($sudo->status, ['host_changed', 'unreachable'], true)) return $sudo;
        return $sudo->status === 'ok' ? $r : new ShellResult('no_sudo');
    }

    private function acceptAccount(Operation $op, array $account, ShellResult $r, bool $saved = false): Operation
    {
        $this->credentials->put($op->id, $account);
        $op->fill(['username' => $account['user'], 'expires_at' => now()->addMinutes(2)]);
        if ($r->status === 'password_expired')
            return $this->state($op, 'password_expired', 'The sensor account '.$account['user'].' asks for a new password.');
        $op->fill(['method' => 'account', 'identity' => $this->agent->identity($r)]);
        return $this->state($op, 'checked', $saved ? 'Access checked using a saved sensor account. Confirm the disk and project before re-provisioning.'
            : 'Access checked. Confirm the disk and project before re-provisioning.');
    }

    public function changePassword(string $id, int $user, string $session, string $new, string $repeat): Operation
    {
        $op = $this->owned($id, $user, $session);
        if ($op->state !== 'password_expired') throw new RuntimeException('Check access before changing the sensor password.');
        $account = $this->credentials->get($id);
        if (!$account) throw new RuntimeException('The credentials expired. Check access again.');
        $this->validateAccount(['user' => $account['user'], 'password' => $new]);
        if (!hash_equals($new, $repeat)) throw new RuntimeException('The new passwords do not match.');
        ModuleOperationLock::run([$op->serial], function () use ($op, $account, $new) {
            $cm = Cm::where('serial', $op->serial)->firstOrFail();
            if ($why = $this->blocker($cm)) throw new RuntimeException($why);
            $this->unchanged($op, $cm, Project::getActive(), $this->addresses->find($cm));
            $r = $this->shell->changePassword($op->address, $op->id, $account['user'], $account['password'], $new);
            if ($r->status !== 'ok') throw new RuntimeException('The sensor did not accept the password change. '.$r->output);
            $this->credentials->put($op->id, ['user' => $account['user'], 'password' => $new]);
            $op->update(['password_changed' => true, 'expires_at' => now()->addMinutes(2)]);
        });
        return $this->check($op->serial, $user, $session, $id);
    }

    public function wipe(string $id, int $user, string $session, string $operator): Operation
    {
        $op = $this->owned($id, $user, $session, false);
        return ModuleOperationLock::run([$op->serial], function () use ($op, $user, $session, $operator) {
            $op = $this->owned($op->id, $user, $session, false);
            if ($op->state !== 'checked') return $op; // idempotent, including an unknown result
            if ($op->expires_at->isPast()) throw new RuntimeException('The access check expired. Check access again.');
            $cm = Cm::where('serial', $op->serial)->firstOrFail();
            if ($why = $this->blocker($cm)) throw new RuntimeException($why);
            $this->unchanged($op, $cm, Project::getActive(), $this->addresses->find($cm));
            $pending = Operation::pending($cm->serial);
            if ($pending && $pending->id !== $op->supersedes_id) throw new RuntimeException('Another re-provision request is already pending.');
            $account = $this->credentials->get($op->id);
            if ($op->method === 'account' && !$account) throw new RuntimeException('The credentials expired. Check access again.');
            $r = $this->execute($op, 'identify', $account);
            $identity = $this->agent->identity($r);
            if (!$identity || $identity !== $op->identity) throw new RuntimeException('The sensor identity or disk changed. Check access again.');
            $this->matchIdentity($cm, $identity);
            $cm->refresh();
            if ($why = $this->blocker($cm)) throw new RuntimeException($why);
            $this->unchanged($op, $cm, Project::getActive(), $this->addresses->find($cm));
            if ($pending) $pending->update(['state' => 'superseded']);
            // Persist intent before sending anything destructive; a dead PHP process leaves a guarded, unknown result.
            $op->update(['state' => 'dispatching', 'expires_at' => now()->addMinutes(30), 'message' => 'Sending the re-provision command.']);
            $cm->update(['durable_bytes' => null, 'durable_at' => null, 'durable_image_id' => null, 'progress_base' => null]);
            $this->log($cm, 'Re-provision requested by '.$operator.' over '.($op->method === 'key' ? "the provisioner's key" : 'account '.$op->username).($op->password_changed ? ' (password changed)' : '').'.');
            try {
                $request = 'wipe '.$identity['serial'].' '.$identity['cid'].' '.$identity['sectors'];
                $r = $this->execute($op, $request, $account);
                if ($r->acknowledges($identity['serial'])) {
                    $this->log($cm, 'Re-provision by '.$operator.': wiped over '.($op->method === 'key' ? "the provisioner's key" : 'account '.$op->username).'.');
                    return $this->state($op, 'waiting', 'Wiped: waiting for the sensor to return to network boot.');
                }
                return $this->state($op, 'unknown', 'The command was sent, but its result is unknown. It will not be repeated automatically. Wait for network boot or check access again.');
            } catch (\Throwable $e) {
                return $this->state($op, 'unknown', 'The SSH connection ended after dispatch. The command will not be repeated automatically.');
            } finally {
                $this->credentials->forget($op->id);
                $this->shell->forget($op->id);
            }
        });
    }

    public function confirmBoot(string $serial, string $operator): void
    {
        ModuleOperationLock::run([$serial], function () use ($serial, $operator) {
            $cm = Cm::where('serial', $serial)->firstOrFail();
            if ($cm->isRunning()) throw new RuntimeException('The module is already being provisioned.');
            $op = Operation::pending($serial);
            $project = Project::getActive();
            if (!$op || !$project?->image) throw new RuntimeException('Select an active project with an image first.');
            $op->update(['project_id' => $project->id, 'project_hash' => ReprovisionIntent::fingerprint($project), 'expires_at' => now()->addMinutes(30),
                'message' => 'Current project confirmed. Restart the module if it is waiting in the utility OS.']);
            $this->log($cm, 'Re-provision: '.$operator.' confirmed '.$project->name.' for the next network boot.');
        });
    }

    public function cancel(string $id, int $user, string $session): void
    {
        $op = $this->owned($id, $user, $session, false);
        ModuleOperationLock::run([$op->serial], function () use ($op) {
            $op->refresh();
            if (in_array($op->state, ['checking','account','password_expired','checked'], true)) $op->update(['state' => 'cancelled']);
            $this->credentials->forget($op->id);
            $this->shell->forget($op->id);
        });
    }

    private function execute(Operation $op, string $request, ?array $account): ShellResult
    {
        return $op->method === 'key' ? $this->shell->key($op->address, $op->id, $request)
            : $this->shell->account($op->address, $op->id, $account['user'], $account['password'], $this->agent->command($request, $op->identity['storage'], str_starts_with($request, 'wipe ')));
    }
    private function owned(string $id, int $user, string $session, bool $fresh = true): Operation
    {
        $op = Operation::findOrFail($id);
        if ($op->user_id !== $user || !hash_equals($op->session_hash, hash('sha256', $session))) abort(403);
        if ($fresh && $op->expires_at->isPast()) { $this->credentials->forget($id); throw new RuntimeException('The access check expired. Check access again.'); }
        return $op;
    }
    private function unchanged(Operation $op, Cm $cm, ?Project $project, ?array $address): void
    {
        if (!$project || !$address || $op->serial !== $cm->serial || $op->address !== $address['address'] || $op->mac !== $address['mac']
            || $op->run_started_at !== $cm->provisioning_started_at?->toISOString() || $op->project_id != $project->id
            || !hash_equals($op->project_hash, ReprovisionIntent::fingerprint($project)))
            throw new RuntimeException('The module, address or project changed. Close this dialog and check access again.');
    }
    private function matchIdentity(Cm $cm, array $i): void
    {
        if (strtolower($cm->serial) !== $i['serial']) throw new RuntimeException('A different device answered at this address (serial '.$i['serial'].').');
        if (($cm->provisioning_storage && $cm->provisioning_storage !== $i['storage'])
            || ($cm->cid && strtolower($cm->cid) !== $i['cid']) || ($cm->storage && (int) $cm->storage !== (int) $i['sectors'] * 512))
            throw new RuntimeException('The sensor disk does not match its last provisioning record.');
    }
    private function validateAccount(array $account): void
    {
        if (!is_string($account['user'] ?? null) || !preg_match('/^[a-z_][a-z0-9_-]{0,31}$/D', $account['user'])
            || !is_string($account['password'] ?? null) || strlen($account['password']) < 1 || strlen($account['password']) > 1024
            || preg_match('/[\r\n\x00]/', $account['password'])) throw new RuntimeException('Enter a valid sensor login and password without line breaks.');
    }
    private function state(Operation $op, string $state, string $message): Operation
    {
        $op->fill(['state' => $state, 'message' => $message])->save(); return $op;
    }
    private function error(ShellResult $r): string
    {
        return match ($r->status) {
            'denied' => 'The sensor did not accept this account password.',
            'no_sudo' => 'The sensor account could not obtain sudo access.',
            'unreachable' => 'The sensor does not answer SSH. It may be off, booting or still in the utility OS; use PoE restart for the utility OS.',
            'host_changed' => 'The SSH host key changed during this operation. Close the dialog and check access again.',
            default => 'The sensor could not be checked. '.$r->output,
        };
    }
    private function log(Cm $cm, string $message): void
    {
        Cmlog::create(['cm' => $cm->serial, 'board' => $cm->provisioning_board, 'loglevel' => 'info', 'msg' => $message]);
    }
}
