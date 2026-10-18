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

    public function install(string $storage, string $public): string
    {
        return view('scriptexecute.reprovision-install', ['agent' => $this->script($storage), 'public' => $public])->render();
    }

    public function identity(ShellResult $result): ?array
    {
        if ($result->status !== 'ok') return null;
        return $result->identity ?? self::parse($result->output);
    }

    public static function parse(string $output): ?array
    {
        $values = [];
        foreach (explode("\n", trim($output)) as $line) {
            $pair = explode('=', trim($line), 2);
            if (count($pair) === 2 && in_array($pair[0], ['agent','serial','host','storage','cid','sectors'], true)) {
                if (isset($values[$pair[0]])) return null;
                $values[$pair[0]] = $pair[1];
            }
        }
        if (count($values) !== 6 || $values['agent'] !== '1'
            || !preg_match('/^[a-f0-9]{16}$/D', $values['serial']) || $values['serial'] === str_repeat('0', 16)
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,252}$/D', $values['host'])
            || !preg_match('#^/dev/mmcblk[0-9]+$#D', $values['storage'])
            || !preg_match('/^[a-f0-9]{32}$/D', $values['cid'])
            || !ctype_digit($values['sectors']) || strlen($values['sectors']) > 13 || (int) $values['sectors'] < 2048)
            return null;
        return $values;
    }
}
