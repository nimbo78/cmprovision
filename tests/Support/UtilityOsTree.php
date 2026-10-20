<?php

namespace Tests\Support;

/** A throwaway application directory with both utility OSes, their MANIFESTs, the link etc/boot to the legacy one and a
    dnsmasq.conf that follows the link; config points App\Services\UtilityOs at it */
trait UtilityOsTree
{
    protected string $tree;

    protected function makeTree(): void
    {
        $this->tree = sys_get_temp_dir().'/uos-tree-'.bin2hex(random_bytes(4));
        mkdir($this->tree.'/etc', 0755, true);
        $this->osDir('scriptexecute', "version=legacy\nbits=32\nkernel=Linux 5.4\nbuilt=2022-11\n");
        $this->osDir('scriptexecute64', "version=2026.10\nbits=64\nkernel=Linux 6.18.50 (raspberrypi/linux stable_20260911)\nbuilt=2026-10-17\n");
        symlink('../scriptexecute', $this->tree.'/etc/boot');
        file_put_contents($this->tree.'/etc/dnsmasq.conf', "port=0\nenable-tftp\ntftp-root={$this->tree}/etc/boot\n");
        config(['cmprovision.boot_root' => $this->tree, 'cmprovision.boot_link' => $this->tree.'/etc/boot',
                'cmprovision.dnsmasq_conf' => $this->tree.'/etc/dnsmasq.conf']);
        $this->beforeApplicationDestroyed(fn () => exec('rm -rf '.escapeshellarg($this->tree)));
    }

    protected function osDir(string $dir, string $manifestLines): void
    {
        @mkdir($this->tree.'/'.$dir);
        file_put_contents($this->tree.'/'.$dir.'/kernel8.img', 'kernel of '.$dir);
        file_put_contents($this->tree.'/'.$dir.'/MANIFEST', "# cmprovision utility OS\n".$manifestLines
            .'file='.hash('sha256', 'kernel of '.$dir).' '.strlen('kernel of '.$dir)." kernel8.img\n");
    }
}
