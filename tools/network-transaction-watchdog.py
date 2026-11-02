"""Independent systemd timer for network rollback."""
import re
import subprocess


class WatchdogError(RuntimeError):
    pass


class Watchdog:
    def __init__(self, unit='cmprovision-network-revert', command=None, delay=125, run=None):
        if not re.fullmatch(r'[a-z0-9-]{1,80}', unit) or type(delay) is not int or not 1 <= delay <= 3600:
            raise WatchdogError('Invalid watchdog configuration.')
        self.unit = unit
        self.command = command or ['/usr/lib/cmprovision/network-transaction', 'recover']
        self.delay = delay
        self.run = run or self._run

    @staticmethod
    def _run(*args):
        result = subprocess.run(args, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=15, check=False)
        return result.returncode, result.stderr.strip()

    def _name(self, transaction_id):
        if not isinstance(transaction_id, str) or not re.fullmatch(r'[0-9a-f]{32}', transaction_id):
            raise WatchdogError('Invalid network transaction identifier.')
        return self.unit + '-' + transaction_id

    def schedule(self, transaction_id):
        code, message = self.run('systemd-run', '--quiet', '--collect', '--unit=' + self._name(transaction_id),
            '--on-active=' + str(self.delay) + 's', '--timer-property=AccuracySec=1s',
            '--property=Restart=on-failure', '--property=RestartSec=10s', '--property=StartLimitIntervalSec=0',
            '--', *self.command, transaction_id)
        if code:
            raise WatchdogError('Could not schedule network recovery: ' + message[:200])

    def cancel(self, transaction_id):
        # If the timer already fired, stopping it may report that the transient unit is gone.
        # Its recovery invocation is idempotent when state has already been cleared.
        self.run('systemctl', 'stop', self._name(transaction_id) + '.timer')
