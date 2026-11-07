#!/usr/bin/env python3
"""Real loopback sshd/forced-command test. Root in disposable Linux only; no real accounts changed."""
import json
import os
from pathlib import Path
import signal
import socket
import subprocess
import tempfile
import time

assert os.geteuid() == 0
app = Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='cmp-station-ssh-', dir='/run') as directory:
    root = Path(directory)
    for name in ('host', 'client'):
        subprocess.run(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-f', str(root / name)], check=True)
    endpoint = root / 'endpoint'
    endpoint.write_text('''#!/usr/bin/python3
import json, os, pathlib, sys
root = pathlib.Path(__file__).parent
action = os.environ.get('SSH_ORIGINAL_COMMAND')
if action == 'identity':
 print(json.dumps({'schema':1,'serial':'10000000e611110c','cid':'a'*32,'run':'x'*40}))
elif action == 'apply':
 data = sys.stdin.buffer.read(32769)
 mode = (root/'mode').read_text()
 if mode.startswith('generic:'):
  print('image-access:error:' + mode.split(':', 1)[1])
  print(data.decode(), file=sys.stderr)
  sys.exit(1)
 if mode == 'fail':
  print(data.decode(), file=sys.stderr)
  sys.exit(1)
 (root/'received').write_bytes(data)
 print('applied')
else:
 sys.exit(1)
''')
    endpoint.chmod(0o700)
    (root / 'authorized_keys').write_text('restrict,command="' + str(endpoint) + '" ' + (root / 'client.pub').read_text())
    (root / 'authorized_keys').chmod(0o600)
    with socket.socket() as channel:
        channel.bind(('127.0.0.1', 0))
        port = channel.getsockname()[1]
    (root / 'sshd_config').write_text(f'''ListenAddress 127.0.0.1
Port {port}
HostKey {root}/host
PidFile {root}/pid
UsePAM yes
PermitRootLogin forced-commands-only
PasswordAuthentication no
KbdInteractiveAuthentication no
AllowUsers root
AuthorizedKeysFile {root}/authorized_keys
DisableForwarding yes
PermitTTY no
PrintMotd no
LogLevel VERBOSE
''')
    Path('/run/sshd').mkdir(exist_ok=True)
    log = (root / 'sshd.log').open('w')
    server = subprocess.Popen(['/usr/sbin/sshd', '-D', '-e', '-f', str(root / 'sshd_config')],
                              stdout=log, stderr=log, start_new_session=True)
    try:
        for _ in range(40):
            try:
                with socket.create_connection(('127.0.0.1', port), timeout=.1): break
            except OSError: time.sleep(.1)
        env = dict(os.environ, CMP_STATION_SSH_FIXTURE=directory, CMP_STATION_SSH_PORT=str(port))
        result = subprocess.run(['php', 'vendor/bin/phpunit', 'tests/Feature/StationSetupSshTest.php'], cwd=app, env=env)
        if result.returncode:
            print((root / 'sshd.log').read_text())
            raise SystemExit(result.returncode)
    finally:
        os.killpg(server.pid, signal.SIGTERM)
        server.wait(timeout=5)
        log.close()
