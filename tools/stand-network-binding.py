"""Root-owned dnsmasq interface selection for a guarded stand network transfer."""
import os
import secrets
import stat
import subprocess


class BindingError(RuntimeError):
    pass


class Binding:
    def __init__(self, dnsmasq_start, path='/etc/default/cmprovision-stand-interface'):
        self.dnsmasq_start = dnsmasq_start
        self.path = path

    def read(self):
        return self.dnsmasq_start.binding(self.path)

    def validate(self, interface):
        # This reads the web-owned dnsmasq configuration as www-data and rejects includes or
        # interface overrides which could make the DHCP/TFTP binding broader than intended.
        self.dnsmasq_start.command(interface)

    def write(self, interface):
        directory, name = os.path.split(self.path)
        info = os.lstat(directory)
        if not stat.S_ISDIR(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o022:
            raise BindingError('The stand configuration directory is not secure.')
        if interface is not None and (not isinstance(interface, str) or not self.dnsmasq_start.IFNAME.fullmatch(interface)):
            raise BindingError('The stand interface is invalid.')
        # Validate an existing file before replacing or removing it; never follow a symlink.
        self.read()
        directory_fd = os.open(directory, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        temporary = '.cmprovision-stand-' + secrets.token_hex(12)
        try:
            if interface is None:
                try:
                    os.unlink(name, dir_fd=directory_fd)
                except FileNotFoundError:
                    pass
            else:
                fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o644, dir_fd=directory_fd)
                try:
                    os.fchmod(fd, 0o644)
                    os.write(fd, (interface + '\n').encode('ascii'))
                    os.fsync(fd)
                finally:
                    os.close(fd)
                os.replace(temporary, name, src_dir_fd=directory_fd, dst_dir_fd=directory_fd)
            os.fsync(directory_fd)
        finally:
            try:
                os.unlink(temporary, dir_fd=directory_fd)
            except FileNotFoundError:
                pass
            os.close(directory_fd)


class DnsmasqService:
    def __init__(self, run=None, defer_start=False):
        self.run = run or self._run
        self.defer_start = defer_start

    @staticmethod
    def _run(*args):
        result = subprocess.run(args, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=30, check=False)
        if result.returncode:
            raise BindingError('The DHCP/TFTP service could not be checked or restarted: ' + result.stderr.strip()[:160])
        return result.stdout.strip()

    def active(self):
        output = self.run('systemctl', 'show', 'cmprovision-dnsmasq.service', '--property=LoadState', '--property=ActiveState')
        fields = dict(line.split('=', 1) for line in output.splitlines() if '=' in line)
        if fields.get('LoadState') not in ('loaded', 'masked') or fields.get('ActiveState') not in ('active', 'inactive', 'failed'):
            raise BindingError('The DHCP/TFTP service state is unavailable or changing.')
        return fields['ActiveState'] == 'active'

    def reconcile(self, should_be_active):
        if should_be_active:
            if self.defer_start:
                # Boot recovery is ordered Before=dnsmasq. Waiting for its restart here
                # would deadlock on our own unit; queue it and let recovery finish first.
                self.run('systemctl', '--no-block', 'restart', 'cmprovision-dnsmasq.service')
                return
            self.run('systemctl', 'restart', 'cmprovision-dnsmasq.service')
            if not self.active():
                raise BindingError('The DHCP/TFTP service did not start on the selected interface.')
        elif self.active():
            raise BindingError('The DHCP/TFTP service was started outside this operation; it was not changed.')
