#!/usr/bin/env python3
"""A temporary loopback sshd and Linux account; run as root in a disposable Linux test environment."""
import os, pathlib, secrets, shutil, signal, socket, subprocess, tempfile

assert os.geteuid() == 0, 'This integration fixture needs root in the disposable Linux test environment.'
app = pathlib.Path(__file__).resolve().parents[2]
fixture = pathlib.Path(tempfile.mkdtemp(prefix='cmprov-ssh-'))
fixture.chmod(0o755)
user = 'cmpt_' + secrets.token_hex(4)
password = 'Fixture-Only-123!'
sudoers = pathlib.Path('/etc/sudoers.d') / user
server = None
created = False
def run(args, **kw): return subprocess.run(args, check=True, **kw)
try:
    run(['useradd','-m','-d',str(fixture/'home'),'-s','/bin/sh',user])
    created = True
    run(['chpasswd'],input=user+':'+password+'\n',text=True)
    sudoers.write_text(user+' ALL=(root) ALL\n'); sudoers.chmod(0o440)
    run(['ssh-keygen','-q','-t','ed25519','-N','','-f',str(fixture/'host')])
    run(['php','-r',"require 'vendor/autoload.php'; $a=require 'bootstrap/app.php'; $a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo app(App\\Services\\Sensor\\ProvisionerKey::class)->ensure();"],cwd=app,stdout=(fixture/'pub').open('w'))
    (fixture/'forced').write_text('#!/bin/sh\nprintf "forced=%s\\n" "$SSH_ORIGINAL_COMMAND"\n')
    (fixture/'forced').chmod(0o755)
    (fixture/'root-key').write_text('restrict,command="'+str(fixture/'forced')+'" '+(fixture/'pub').read_text()+'\n')
    with socket.socket() as sock:
        sock.bind(('127.0.0.1',0)); port=sock.getsockname()[1]
    config = fixture/'sshd_config'
    config.write_text(f'''ListenAddress 127.0.0.1
Port {port}
HostKey {fixture}/host
PidFile {fixture}/pid
UsePAM yes
PasswordAuthentication yes
KbdInteractiveAuthentication no
PermitRootLogin forced-commands-only
StrictModes no
AuthorizedKeysFile {fixture}/root-key
AllowUsers root {user}
PrintMotd no
LogLevel ERROR
''')
    pathlib.Path('/run/sshd').mkdir(exist_ok=True)
    server=subprocess.Popen(['/usr/sbin/sshd','-D','-e','-f',str(config)],stdout=(fixture/'server.log').open('w'),stderr=subprocess.STDOUT,start_new_session=True)
    import time
    for attempt in range(50):
        try:
            with socket.create_connection(('127.0.0.1',port),timeout=.1): break
        except OSError: time.sleep(.1)
    env=dict(os.environ,CMPROVISION_SSH_TEST_PORT=str(port),CMPROVISION_SSH_TEST_USER=user,CMPROVISION_SSH_TEST_PASSWORD=password,CMPROVISION_SSH_TEST_SUDOERS=str(sudoers))
    result=subprocess.run(['php','artisan','test','--filter=OpenSshSensorShellTest'],cwd=app,env=env)
    if result.returncode: print((fixture/'server.log').read_text())
    raise SystemExit(result.returncode)
finally:
    if server:
        try: os.killpg(server.pid,signal.SIGTERM)
        except ProcessLookupError: pass
        server.wait(timeout=5)
    if sudoers.exists(): sudoers.unlink()
    if created: subprocess.run(['userdel',user],check=True)
    assert fixture.parent == pathlib.Path('/tmp') and fixture.name.startswith('cmprov-ssh-')
    shutil.rmtree(fixture)
