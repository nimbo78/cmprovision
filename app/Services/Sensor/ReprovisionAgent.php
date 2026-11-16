<?php

namespace App\Services\Sensor;

class ReprovisionAgent
{
    public function script(?string $storage = null): string
    {
        if ($storage !== null && !preg_match('#^/dev/mmcblk[0-9]+$#D', $storage))
            throw new \InvalidArgumentException('Re-provision supports a whole eMMC/SD disk with an ext4 second root partition.');
        return view('scriptexecute.reprovision-agent', ['target' => $storage])->render();
    }

    public function command(string $request, ?string $storage = null, bool $sudo = false): string
    {
        $script = 'SSH_ORIGINAL_COMMAND='.escapeshellarg($request)."\n".$this->script($storage);
        return ($sudo ? 'sudo -k -S -p \'\' ' : '').'sh -c '.escapeshellarg($script);
    }

    /** $mark: the provisioner's mark (ModuleMark) to install with the agent, ['station', 'boots', 'url' of the module's key],
        or ['skip' => why there is none] */
    public function install(string $storage, string $public, ?array $mark = null): string
    {
        return view('scriptexecute.reprovision-install', ['agent' => $this->script($storage), 'public' => $public, 'mark' => $mark,
            'markScript' => $mark !== null && !isset($mark['skip']) ? rtrim(view('scriptexecute.module-mark')->render(), "\n") : ''])->render();
    }

    public function identity(ShellResult $result): ?array
    {
        if ($result->status !== 'ok') return null;
        return $result->identity ?? self::parse($result->output);
    }

    public static function parse(string $output): ?array
    {
        if (strlen($output) > 4096) return null;
        $values = [];
        foreach (explode("\n", trim($output)) as $line) {
            $pair = explode('=', trim($line), 2);
            if (count($pair) === 2 && in_array($pair[0], ['agent','serial','host','storage','cid','sectors','model','revision','mac','boot_order','provisioner'], true)) {
                if (isset($values[$pair[0]])) return null;
                $values[$pair[0]] = $pair[1];
            }
        }
        $required = ['agent','serial','host','storage','cid','sectors'];
        if (($values['agent'] ?? '') === '2') $required = array_merge($required, ['model','revision','mac','boot_order','provisioner']);
        if (array_diff($required, array_keys($values))) return null;
        if (!isset($values['agent']) || !in_array($values['agent'], ['1','2'], true)
            || count($values) !== ($values['agent'] === '1' ? 6 : 11)
            || !preg_match('/^[a-f0-9]{16}$/D', $values['serial']) || $values['serial'] === str_repeat('0', 16)
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,252}$/D', $values['host'])
            || !preg_match('#^/dev/mmcblk[0-9]+$#D', $values['storage'])
            || !preg_match('/^[a-f0-9]{32}$/D', $values['cid'])
            || !ctype_digit($values['sectors']) || strlen($values['sectors']) > 13 || (int) $values['sectors'] < 2048)
            return null;
        if ($values['agent'] === '2' && (
            !preg_match('/^[A-Za-z0-9][A-Za-z0-9 .()+_\/-]{0,119}$/D', $values['model'])
            || !preg_match('/^(?:[a-f0-9]{1,8}|unknown)$/D', $values['revision'])
            || !preg_match('/^(?:[a-f0-9]{2}:){5}[a-f0-9]{2}$/D', $values['mac'])
            || !preg_match('/^(?:0x[a-f0-9]{1,8}|unknown)$/D', $values['boot_order'])
            || !in_array($values['provisioner'], ['0','1'], true))) return null;
        return $values;
    }
}
