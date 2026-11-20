#!/usr/bin/env python3
"""Choose the GPIO display on a mounted station image before its first boot."""

import argparse
import json
from pathlib import Path
import re
import tempfile


BEGIN = '# BEGIN cmprovision display profile'
END = '# END cmprovision display profile'
OVERLAY = 'vc4-kms-dpi-hyperpixel4'
PROFILES = {
    'waveshare-144': ('dtparam=spi=on',),
    'hyperpixel-4-touch': ('dtparam=i2c_arm=off', 'dtparam=spi=off', 'dtoverlay=' + OVERLAY),
}


def replace_block(config, directives):
    lines = config.splitlines()
    if BEGIN in lines or END in lines:
        if lines.count(BEGIN) != 1 or lines.count(END) != 1 or lines.index(BEGIN) >= lines.index(END):
            raise ValueError('The managed display profile block is incomplete or repeated.')
        del lines[lines.index(BEGIN):lines.index(END) + 1]
    if any(re.match(r'\s*dtoverlay\s*=\s*(?:vc4-kms-dpi-|hyperpixel)', line) for line in lines):
        raise ValueError('Remove the existing unmanaged DPI overlay before selecting a display profile.')
    base = '\n'.join(lines).rstrip('\n')
    return base + '\n\n' + '\n'.join((BEGIN, '[all]', *directives, END)) + '\n'


def write_config(path, content, mode=0o644):
    with tempfile.NamedTemporaryFile(mode='w', encoding='utf-8', newline='\n',
                                     dir=path.parent, prefix=path.name + '.', delete=False) as output:
        tmp = Path(output.name)
        output.write(content)
    try:
        tmp.chmod(mode)
        tmp.replace(path)
    finally:
        tmp.unlink(missing_ok=True)


def prepare(root, profile):
    if profile == 'default':
        return
    if profile not in PROFILES:
        raise ValueError('Unknown boot display profile.')
    if (root / 'var/lib/cmprovision-image/firstboot-ready').exists():
        raise ValueError('Select a display on an unbooted station image.')
    if not (root / 'usr/lib/systemd/system/fpms-lite.service').is_file() or not (
            root / 'usr/lib/cmprovision/image-fpms').is_file():
        raise ValueError('The station image needs fpms-lite and its image controller.')
    boot = root / 'boot/firmware'
    config = boot / 'config.txt'
    fpms = root / 'etc/default/cmprovision-fpms.json'
    for path in (config, fpms):
        if path.is_symlink() or path.resolve() != path.absolute():
            raise ValueError('A display configuration path contains a symlink.')
    if profile == 'hyperpixel-4-touch' and not (boot / 'overlays' / (OVERLAY + '.dtbo')).is_file():
        raise ValueError('The firmware partition is missing the HyperPixel overlay.')
    text = replace_block(config.read_text(encoding='utf-8'), PROFILES[profile])
    if not fpms.parent.is_dir():
        raise ValueError('The station image configuration directory is missing.')
    write_config(config, text, config.stat().st_mode & 0o777)
    # This root-owned setting lets the image watcher start FPMS after firstboot.
    write_config(fpms, json.dumps({'enabled': True, 'open_on_start': True}) + '\n')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('root', type=Path, help='mounted image root with its boot partition at boot/firmware')
    parser.add_argument('profile', choices=('default', *PROFILES))
    args = parser.parse_args()
    try:
        prepare(args.root.resolve(), args.profile)
    except (OSError, ValueError) as error:
        parser.exit(1, str(error) + '\n')


if __name__ == '__main__':
    main()
