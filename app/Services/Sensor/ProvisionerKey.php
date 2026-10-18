<?php

namespace App\Services\Sensor;

use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Symfony\Component\Process\Process;

class ProvisionerKey
{
    public function path(): string { return storage_path('app/ssh/id_ed25519'); }

    public function fingerprint(string $public): string
    {
        $parts = explode(' ', $public);
        $decoded = base64_decode($parts[1] ?? '', true);
        if (!$decoded) throw new RuntimeException('Invalid provisioner public key.');
        return 'SHA256:'.rtrim(base64_encode(hash('sha256', $decoded, true)), '=');
    }

    public function ensure(): string
    {
        return Cache::lock('reprovision-key', 15)->block(5, function () {
            $path = $this->path();
            $dir = dirname($path);
            if (is_link($dir) || is_link($path) || is_link($path.'.pub'))
                throw new RuntimeException('The provisioner key path contains a symbolic link.');
            if (!is_dir($dir) && !mkdir($dir, 0700, true))
                throw new RuntimeException('Cannot create the provisioner key directory.');
            chmod($dir, 0700);
            if (!is_file($path)) {
                $tmp = $dir.'/.key-'.bin2hex(random_bytes(8));
                try {
                    $p = new Process(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-C', 'cmprovision', '-f', $tmp]);
                    $p->setTimeout(10)->run();
                    if (!$p->isSuccessful() || !rename($tmp, $path))
                        throw new RuntimeException('Cannot generate the provisioner SSH key.');
                } finally {
                    @unlink($tmp); @unlink($tmp.'.pub');
                }
            }
            chmod($path, 0600);
            $p = new Process(['ssh-keygen', '-y', '-f', $path]);
            $p->setTimeout(5)->run();
            if (!$p->isSuccessful() || !preg_match('/^(ssh-ed25519 [A-Za-z0-9+\/=]+)(?: .*)?$/D', trim($p->getOutput()), $match))
                throw new RuntimeException('The provisioner SSH key cannot be read; it was not replaced.');
            $public = $match[1].' cmprovision';
            file_put_contents($path.'.pub', $public."\n", LOCK_EX);
            chmod($path.'.pub', 0644);
            return $public;
        });
    }
}
