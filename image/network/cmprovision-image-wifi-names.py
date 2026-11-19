#!/usr/bin/python3
"""Name the onboard CM4 radio and one additional radio before NetworkManager."""

from pathlib import Path
import subprocess
import sys
import time


SYS_NET = Path('/sys/class/net')
DISCOVERY_SECONDS = 5


def inventory(net):
    radios = {}
    for interface in net.iterdir():
        device = interface / 'device'
        driver = device / 'driver'
        if not driver.is_symlink() or not any((interface / p).is_dir() for p in ('phy80211', 'wireless')):
            continue
        radios[interface.name] = (driver.resolve().name == 'brcmfmac'
                                  and (device / 'subsystem').resolve().name == 'sdio')
    return radios


def wait_for_radios(net):
    deadline = time.monotonic() + DISCOVERY_SECONDS
    while True:
        radios = inventory(net)
        if len(radios) >= 2 or time.monotonic() >= deadline:
            return radios
        time.sleep(0.25)


def name_moves(radios, occupied):
    onboard = [name for name, builtin in radios.items() if builtin]
    if len(onboard) != 1 or len(radios) > 2:
        return []
    external = [name for name, builtin in radios.items() if not builtin]
    changes = [(old, f'cmpwifi{slot}tmp', f'wlan{slot}')
               for slot, old in enumerate(onboard + external) if old != f'wlan{slot}']
    sources = {old for old, _, _ in changes}
    for _, temporary, target in changes:
        if temporary in occupied or target in occupied - sources:
            raise ValueError(f'A name needed for {target} is already occupied')
    # Vacate both kernel names before assigning either final name.
    return ([(old, temporary) for old, temporary, _ in changes]
            + [(temporary, target) for _, temporary, target in changes])


def apply_moves(moves, rename):
    completed = []
    try:
        for old, new in moves:
            rename(old, new)
            completed.append((old, new))
    except (OSError, subprocess.SubprocessError):
        for old, new in reversed(completed):
            try:
                rename(new, old)
            except (OSError, subprocess.SubprocessError) as error:
                print(f'Unable to restore {old}: {error}', file=sys.stderr)
        raise


def rename_link(old, new):
    subprocess.run(['/usr/sbin/ip', 'link', 'set', 'dev', old, 'name', new], check=True, timeout=2)


def main():
    if subprocess.run(['/usr/bin/systemctl', 'is-active', '--quiet', 'NetworkManager.service'],
                      timeout=2).returncode == 0:
        raise ValueError('Wi-Fi naming must run before NetworkManager starts')
    radios = wait_for_radios(SYS_NET)
    if not any(radios.values()) or len(radios) > 2:
        return
    subprocess.run(['/usr/bin/udevadm', 'wait', '--initialized=yes', '--timeout=4',
                    *(str(SYS_NET / name) for name in radios)], check=True, timeout=5)
    radios = inventory(SYS_NET)
    apply_moves(name_moves(radios, {interface.name for interface in SYS_NET.iterdir()}), rename_link)
    found = inventory(SYS_NET)
    print('Station Wi-Fi names: ' + ', '.join(f'{name}={"onboard" if builtin else "additional"}'
                                              for name, builtin in sorted(found.items())))


if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError, subprocess.SubprocessError) as error:
        sys.exit(f'Unable to set station Wi-Fi names: {error}')
