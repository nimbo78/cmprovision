"""Root-only transport proof. Executes only a Python identity probe, never a power action."""
import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile


def check():
    if os.geteuid() != 0:
        raise RuntimeError('Run this isolated systemd check as root.')
    source = Path(__file__).resolve().parents[2] / 'tools/station-power.py'
    directory = Path(tempfile.mkdtemp(prefix='cmprovision-power-proof-', dir='/run'))
    directory.chmod(0o755)
    try:
        shutil.copyfile(source, directory / 'controller.py')
        (directory / 'probe.py').write_text('''import json, os, sys
request = json.load(sys.stdin)
print(json.dumps({'ok': True, 'result': {'uid': os.geteuid(), 'request': request}}))
''')
        # The sandboxed root process uses the actual product bridge, replacing its
        # exact Artisan tail with a harmless read-only identity probe. The system
        # manager must still pass stdin/stdout and start it as www-data outside NNP.
        (directory / 'outer.py').write_text('''import importlib.util, json, pathlib, subprocess
root = pathlib.Path(__file__).parent
spec = importlib.util.spec_from_file_location('controller', root / 'controller.py')
power = importlib.util.module_from_spec(spec)
spec.loader.exec_module(power)
def probe(command, **options):
    assert command[-4:] == ['/usr/bin/php', '/var/lib/cmprovision/artisan', 'station:power', '--json']
    assert '--uid=www-data' in command
    command = command[:-4] + ['/usr/bin/python3', str(root / 'probe.py')]
    return subprocess.run(command, **options)
print(json.dumps(power.panel({'operation': 'status'}, run=probe)))
''')
        command = ['/usr/bin/systemd-run', '--quiet', '--wait', '--pipe', '--collect',
                   '--unit=' + directory.name, '--property=NoNewPrivileges=yes',
                   '--property=ProtectSystem=strict', '--property=ProtectHome=yes',
                   '--property=PrivateTmp=yes', '--property=ProtectKernelTunables=yes',
                   '--property=ProtectKernelModules=yes', '--property=ProtectControlGroups=yes',
                   '--property=RestrictAddressFamilies=AF_UNIX',
                   '--setenv=PYTHONDONTWRITEBYTECODE=1', '/usr/bin/python3', str(directory / 'outer.py')]
        result = subprocess.run(command, text=True, capture_output=True, timeout=25, check=True)
        response = json.loads(result.stdout)
        import pwd
        assert response == {'ok': True, 'result': {'uid': pwd.getpwnam('www-data').pw_uid,
                                                  'request': {'operation': 'status'}}}, response
        print('Sandboxed FPMS -> systemd -> www-data JSON transport: passed. No power action executed.')
    finally:
        shutil.rmtree(directory)


if __name__ == '__main__':
    check()
