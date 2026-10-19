"""The commands of the legacy utility OS (scriptexecute/scriptexecute.img) for utility-os/legacy-commands.txt: every
executable or link in bin, sbin, usr/bin and usr/sbin of its initramfs, sorted, minus those left out below with their
reason. tools/utility-os/test.sh checks that the new OS has every command of the list.
    python3 -I tools/utility-os/legacy_commands.py scriptexecute/scriptexecute.img > utility-os/legacy-commands.txt"""
import gzip
import lzma
import sys

LEFT_OUT = [
    ('camera, multimedia and VideoCore test tools of rpi-userland, which no provisioning script uses',
     lambda c: c.startswith('containers_') or c in ('mmal_vc_diag', 'raspistill', 'raspivid', 'raspividyuv', 'raspiyuv',
                                                     'tvservice', 'vchiq_test', 'vcsmem', 'vcfiled')),
    ("hooks of rpi-userland's dtoverlay; the dtoverlay of raspberrypi/utils does not call them",
     lambda c: c in ('dtoverlay-pre', 'dtoverlay-post')),
    ('test programs of sysfsutils 2.1.0; sysfsutils 2.1.1 (libsysfs of buildroot 2025.02) installs only systool',
     lambda c: c in ('dlist_test', 'get_device', 'get_driver', 'get_module')),
    ("e2fsprogs' e2scrub needs bash and LVM, and the utility OS has neither",
     lambda c: c in ('e2scrub', 'e2scrub_all')),
]

sys.stdout.reconfigure(newline='\n')   # LF on Windows too: the file is committed as it is written
data = open(sys.argv[1], 'rb').read()
if data[:2] == b'\x1f\x8b':
    data = gzip.decompress(data)
elif data[:6] == b'\xfd7zXZ\x00':
    data = lzma.decompress(data)

commands = set()
off = 0
while off < len(data) and data[off:off + 6] in (b'070701', b'070702'):
    fields = [int(data[off + 6 + i * 8:off + 14 + i * 8], 16) for i in range(13)]
    mode, size, namesize = fields[1], fields[6], fields[11]
    name = data[off + 110:off + 110 + namesize - 1].decode()
    off = (off + 110 + namesize + 3) & ~3
    off = (off + size + 3) & ~3
    if name == 'TRAILER!!!':
        break
    directory, _, command = name.rpartition('/')
    kind = mode & 0o170000
    if directory in ('bin', 'sbin', 'usr/bin', 'usr/sbin') and (kind == 0o120000 or (kind == 0o100000 and mode & 0o111)):
        commands.add(command)

print('# The commands of the legacy utility OS (scriptexecute/scriptexecute.img), made by tools/utility-os/legacy_commands.py:')
print('# the new OS has every one of them (tools/utility-os/test.sh). Left out, with the reason:')
kept = sorted(commands)
for reason, test in LEFT_OUT:
    out = [c for c in kept if test(c)]
    kept = [c for c in kept if not test(c)]
    print('#   %s: %s' % (' '.join(out), reason))
for command in kept:
    print(command)
