import importlib.machinery
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch


HELPER = Path(__file__).resolve().parents[2] / 'public/tools/image-access'
access = importlib.machinery.SourceFileLoader('image_access', str(HELPER)).load_module()
IDENTITY = {'schema': 1, 'serial': '1000000012345678', 'cid': 'a' * 32, 'run': 'r' * 40}
HASH = '$6$rounds=100000$0123456789abcdef$' + 'a' * 86


class ImageAccessTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='cmp-image-access-test-')
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.write('etc/passwd', 'root:x:0:0:root:/root:/bin/bash\nwlanpi:x:1000:1000::/home/wlanpi:/bin/bash\n')
        self.write('etc/group', 'root:x:0:\nsudo:x:27:wlanpi\nwlanpi:x:1000:\n')
        self.write('etc/os-release', 'ID=debian\n')
        self.write('etc/ssh/sshd_config', 'PasswordAuthentication no\n')
        self.write('etc/sudoers', 'root ALL=(ALL:ALL) ALL\n%sudo ALL=(ALL:ALL) ALL\n')
        (self.root / 'home/wlanpi').mkdir(parents=True)
        (self.root / 'run/cmprovision-station-setup').mkdir(parents=True)
        self.write('run/cmprovision-station-setup/host', 'temporary key fixture')
        for tool in ['usr/sbin/useradd', 'usr/sbin/usermod', 'usr/sbin/chpasswd', 'usr/sbin/sshd',
                     'usr/sbin/visudo', 'usr/bin/python3', 'usr/bin/systemctl']:
            self.write(tool, '#!/bin/sh\n');(self.root / tool).chmod(0o755)
        self.commands = []
        self.chowns = []
        ownership = patch.object(access.os, 'chown', side_effect=lambda *args: self.chowns.append(args))
        ownership.start()
        self.addCleanup(ownership.stop)
        self.effective_password = 'yes'
        self.extra_ssh_options = ''
        self.settings = {'schema': 2, 'profile': 'raspios', 'hostname': '', 'username': 'operator',
                         'source_username': '', 'password_hash': HASH, 'keys': [], 'ssh': True,
                         'wifi': None, 'sudo_passwordless': False}

    def write(self, member, value):
        path = self.root / member
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(value)

    def fake_run(self, argv, data=None):
        self.commands.append((argv, data))
        if argv[:2] == ['usermod', '--login']:
            path = self.root / 'etc/passwd'
            path.write_text(path.read_text().replace(argv[-1] + ':x:', argv[2] + ':x:'))
        if argv[:2] == ['usermod', '--shell']:
            path = self.root / 'etc/passwd'
            lines = []
            for line in path.read_text().splitlines():
                row = line.split(':')
                if row[0] == argv[-1]:row[6] = argv[2]
                lines.append(':'.join(row))
            path.write_text('\n'.join(lines) + '\n')
        if argv and argv[0] == 'useradd':
            name = argv[-1]
            with (self.root / 'etc/passwd').open('a') as file:
                file.write(name + ':x:1000:1000::/home/' + name + ':/bin/bash\n')
            (self.root / ('home/' + name)).mkdir()
        if '-T' in argv:
            return ('passwordauthentication ' + self.effective_password + '\npubkeyauthentication yes\nkbdinteractiveauthentication no\nauthorizedkeysfile .ssh/authorized_keys\n' + self.extra_ssh_options).encode()
        return b''

    def configure(self, **changes):
        access.configure(self.root, IDENTITY, dict(self.settings, **changes), self.fake_run)

    def test_automatic_selection_prefers_requested_login_then_primary_uid_without_creating_another_account(self):
        selected = access.select_account(self.root, self.settings)
        self.assertEqual(('wlanpi', 1000, 1000, '/home/wlanpi', False),
                         tuple(selected[k] for k in ['name', 'uid', 'gid', 'home', 'create']))
        selected = access.select_account(self.root, dict(self.settings, username='wlanpi'))
        self.assertEqual('wlanpi', selected['name'])

    def test_explicit_source_and_destination_collision_are_refused(self):
        self.write('etc/passwd', (self.root / 'etc/passwd').read_text() + 'operator:x:1001:1001::/home/operator:/bin/bash\n')
        with self.assertRaisesRegex(access.Failure, 'account_conflict'):
            access.select_account(self.root, dict(self.settings, source_username='wlanpi'))
        with self.assertRaisesRegex(access.Failure, 'account_missing'):
            access.select_account(self.root, dict(self.settings, source_username='absent'))
        self.write('etc/passwd', (self.root / 'etc/passwd').read_text().replace('operator:x:1001:1001:', 'operator:x:1000:1000:'))
        with self.assertRaisesRegex(access.Failure, 'account_conflict'):
            access.select_account(self.root, dict(self.settings, source_username='wlanpi'))

    def test_ambiguous_accounts_require_explicit_selection(self):
        self.write('etc/passwd', 'root:x:0:0::/root:/bin/bash\none:x:1001:1001::/home/one:/bin/bash\ntwo:x:1002:1002::/home/two:/bin/bash\n')
        with self.assertRaisesRegex(access.Failure, 'account_ambiguous'):
            access.select_account(self.root, self.settings)

    def test_only_an_image_without_interactive_users_gets_a_new_account(self):
        self.write('etc/passwd', 'root:x:0:0::/root:/bin/bash\ndaemon:x:1:1::/usr/sbin:/usr/sbin/nologin\n')
        self.assertTrue(access.select_account(self.root, self.settings)['create'])
        with self.assertRaisesRegex(access.Failure, 'account_unsafe'):
            access.select_account(self.root, dict(self.settings, username='daemon'))

    def test_rename_preserves_uid_gid_home_and_password_is_only_on_stdin(self):
        self.configure()
        self.assertIn('operator:x:1000:1000::/home/wlanpi:/bin/bash', (self.root / 'etc/passwd').read_text())
        self.assertTrue((self.root / 'home/wlanpi').is_dir())
        self.assertFalse((self.root / 'home/operator').exists())
        self.assertIn((['chpasswd', '-e'], ('operator:' + HASH + '\n').encode()), self.commands)
        self.assertNotIn(HASH, json.dumps([argv for argv, _ in self.commands]))
        self.assertFalse(any(argv[0] == 'useradd' for argv, _ in self.commands))

    def test_named_service_or_cron_dependency_blocks_rename_before_any_command(self):
        for member, contents in [('usr/lib/systemd/system/vendor.service', '[Service]\nUser=wlanpi\n'),
                                 ('var/spool/cron/crontabs/wlanpi', '@daily true\n')]:
            self.write(member, contents)
            with self.assertRaisesRegex(access.Failure, 'rename_references'):
                self.configure()
            self.assertEqual([], self.commands)
            (self.root / member).unlink()

    def test_existing_login_can_change_password_even_when_services_depend_on_its_name(self):
        self.write('usr/lib/systemd/system/vendor.service', '[Service]\nUser=wlanpi\n')
        self.configure(username='wlanpi')
        self.assertFalse(any(argv[:2] == ['usermod', '--login'] for argv, _ in self.commands))

    def test_absolute_unit_symlink_is_checked_inside_the_image_before_a_rename(self):
        self.write('opt/vendor/agent.service', '[Service]\nUser=wlanpi\n')
        folder = self.root / 'etc/systemd/system'
        folder.mkdir(parents=True)
        (folder / 'agent.service').symlink_to('/opt/vendor/agent.service')
        with self.assertRaisesRegex(access.Failure, 'rename_references'):
            self.configure()
        self.assertEqual([], self.commands)

    def test_enabled_login_template_instances_block_rename_including_escaped_names(self):
        self.write('usr/lib/systemd/system/vendor@.service', '[Service]\nUser=%i\nExecStart=/usr/bin/true\n')
        directory = self.root / 'etc/systemd/system/multi-user.target.wants'
        directory.mkdir(parents=True)
        for instance in ['wlanpi', r'wlan\x70i']:
            with self.subTest(instance=instance):
                link = directory / ('vendor@' + instance + '.service')
                link.symlink_to('/usr/lib/systemd/system/vendor@.service')
                with self.assertRaisesRegex(access.Failure, 'rename_references'):
                    self.configure()
                self.assertEqual([], self.commands)
                link.unlink()

    def test_template_instances_allow_same_login_updates_and_numeric_uid_instances(self):
        self.write('usr/lib/systemd/system/vendor@.service', '[Service]\nUser=%i\nExecStart=/usr/bin/true\n')
        directory = self.root / 'etc/systemd/system/multi-user.target.wants'
        directory.mkdir(parents=True)
        link = directory / 'vendor@wlanpi.service'
        link.symlink_to('/usr/lib/systemd/system/vendor@.service')
        access.preflight(self.root, access.select_account(self.root, self.settings), dict(self.settings, username='wlanpi'))
        link.unlink()
        (directory / 'vendor@1000.service').symlink_to('/usr/lib/systemd/system/vendor@.service')
        self.configure()

    def test_connection_dependent_ssh_matches_are_rejected_before_account_changes(self):
        for condition in ['User operator Address 192.0.2.0/24', 'Host *.example.invalid', 'LocalPort 2222', 'Group sudo']:
            with self.subTest(condition=condition):
                self.write('etc/ssh/sshd_config', 'Match ' + condition + '\n    PasswordAuthentication no\nMatch all\n')
                with self.assertRaisesRegex(access.Failure, 'ssh_policy'):
                    self.configure()
                self.assertFalse(any(argv[0] in {'usermod', 'useradd', 'chpasswd'} for argv, _ in self.commands))
                self.assertFalse((self.root / access.STATE / 'receipt.json').exists())

    def test_ssh_includes_are_expanded_recursively_and_absolute_links_stay_in_the_image(self):
        self.write('etc/ssh/sshd_config', 'Include sshd_config.d/*.conf\n')
        self.write('etc/ssh/sshd_config.d/10-first.conf', 'Include /opt/vendor/ssh.conf\n')
        self.write('opt/vendor/real.conf', 'Match User operator Address 192.0.2.0/24\n    PasswordAuthentication no\n')
        (self.root / 'opt/vendor/ssh.conf').symlink_to('/opt/vendor/real.conf')
        with self.assertRaisesRegex(access.Failure, 'ssh_policy'):
            self.configure()
        self.assertEqual([], self.commands)

    def test_user_only_ssh_matches_are_supported_and_include_cycles_fail_closed(self):
        self.write('etc/ssh/sshd_config', 'Match User observer\n    PasswordAuthentication no\nMatch all\n')
        self.configure()
        self.write('etc/ssh/sshd_config', 'Include /etc/ssh/loop.conf\n')
        self.write('etc/ssh/loop.conf', 'Include /etc/ssh/loop.conf\n')
        with self.assertRaisesRegex(access.Failure, 'ssh_policy'):
            access.preflight(self.root, access.select_account(self.root, self.settings), self.settings)

    def test_escaped_include_globs_fail_closed_instead_of_skipping_conditional_files(self):
        self.write('etc/ssh/sshd_config', r'Include /etc/ssh/sshd_config.d/\[conditional\].conf' + '\n')
        self.write('etc/ssh/sshd_config.d/[conditional].conf', 'Match User operator Address 192.0.2.0/24\n    PasswordAuthentication no\n')
        with self.assertRaisesRegex(access.Failure, 'ssh_policy'):
            self.configure()
        self.assertEqual([], self.commands)

    def test_user_policy_does_not_replace_global_ssh_settings_or_existing_keys_when_empty(self):
        self.write('home/wlanpi/.ssh/authorized_keys', '# existing image key\n')
        self.configure()
        self.assertEqual('# existing image key\n', (self.root / 'home/wlanpi/.ssh/authorized_keys').read_text())
        config = (self.root / 'etc/ssh/sshd_config').read_text()
        self.assertTrue(config.startswith('PasswordAuthentication no\n'))
        policy = (self.root / 'etc/ssh/cmprovision-user-access.conf').read_text()
        self.assertIn('Match User operator\n', policy)
        self.assertIn('PasswordAuthentication yes', policy)
        self.assertIn('PASSWD: ALL', (self.root / 'etc/cmprovision-image-access.sudoers').read_text())

    def test_unsafe_home_symlinks_are_refused_before_account_changes(self):
        (self.root / 'home/wlanpi/.ssh').symlink_to(self.root / 'etc', target_is_directory=True)
        with self.assertRaisesRegex(access.Failure, 'unsafe_path'):
            self.configure()
        self.assertEqual([], self.commands)

    def test_invalid_complete_settings_do_not_partially_mutate_accounts(self):
        for changes in [{'username': 'root'}, {'profile': 'arbitrary'}, {'wifi': {}}, {'ssh': False},
                        {'password_hash': None, 'keys': []}, {'source_username': '../root'}]:
            with self.assertRaises(access.Failure):
                self.configure(**changes)
            self.assertEqual([], self.commands)

    def test_same_run_retry_uses_original_identity_after_an_interrupted_rename(self):
        original = self.fake_run
        interrupted = [False]
        def failure(argv, data=None):
            value = original(argv, data)
            if argv[:2] == ['usermod', '--login'] and not interrupted[0]:
                interrupted[0] = True
                raise RuntimeError('simulated interruption')
            return value
        with self.assertRaises(RuntimeError):
            access.configure(self.root, IDENTITY, self.settings, failure)
        self.commands.clear()
        self.configure()
        self.assertEqual(1, (self.root / 'etc/passwd').read_text().count('operator:x:'))
        self.assertFalse(any(argv[0] == 'useradd' for argv, _ in self.commands))

    def test_applied_retry_is_idempotent_but_changed_settings_are_refused(self):
        self.configure()
        self.commands.clear()
        self.configure()
        self.assertEqual([], self.commands)
        with self.assertRaisesRegex(access.Failure, 'receipt_conflict'):
            self.configure(sudo_passwordless=True)
        self.assertEqual([], self.commands)

    def test_standard_relative_python_symlink_is_supported_without_following_it_outside_image(self):
        python = self.root / 'usr/bin/python3'
        python.rename(python.with_name('python3.13'))
        python.symlink_to('python3.13')
        self.configure()
        self.assertTrue(any(argv[0] == 'chpasswd' for argv, _ in self.commands))

    def test_pristine_pi_user_template_is_reused_and_its_login_shell_is_enabled(self):
        self.write('etc/passwd', 'root:x:0:0::/root:/bin/bash\npi:x:1000:1000::/home/pi:/usr/sbin/nologin\n')
        (self.root / 'home/pi').mkdir()
        self.write('usr/lib/userconf-pi/userconf', '#!/bin/sh\n')
        self.write('usr/bin/cancel-rename', '#!/bin/sh\n')
        (self.root / 'usr/bin/cancel-rename').chmod(0o755)
        self.write('usr/lib/systemd/system/userconfig.service', '[Service]\nExecStart=/usr/lib/userconf-pi/userconf-service\n')
        self.configure()
        self.assertIn('operator:x:1000:1000::/home/pi:/bin/bash', (self.root / 'etc/passwd').read_text())
        self.assertFalse(any(argv[0] == 'useradd' for argv, _ in self.commands))
        self.assertIn((['/usr/bin/cancel-rename', 'operator'], None), self.commands)

    def test_simple_selected_user_sudo_and_subuid_names_follow_a_rename(self):
        self.write('etc/sudoers.d/010-default-user', 'wlanpi ALL=(ALL) NOPASSWD: ALL\nother ALL=(ALL) ALL\n')
        self.write('etc/subuid', 'wlanpi:100000:65536\n')
        self.configure()
        self.assertEqual('operator ALL=(ALL) NOPASSWD: ALL\nother ALL=(ALL) ALL\n',
                         (self.root / 'etc/sudoers.d/010-default-user').read_text())
        self.assertEqual('operator:100000:65536\n', (self.root / 'etc/subuid').read_text())

    def test_conflicting_effective_ssh_policy_is_rejected_before_account_mutation(self):
        self.effective_password = 'no'
        with self.assertRaisesRegex(access.Failure, 'ssh_policy'):
            self.configure()
        self.assertFalse(any(argv[0] in {'usermod', 'useradd', 'chpasswd'} for argv, _ in self.commands))
        self.assertFalse((self.root / 'etc/ssh/cmprovision-user-access.conf').exists())

    def test_login_restrictions_or_forced_commands_cannot_be_reported_as_working_ssh_access(self):
        for options in ['allowusers other\n', 'denyusers operator\n', 'allowgroups restricted\n',
                        'forcecommand /usr/bin/false\n', 'authenticationmethods publickey,password\n']:
            self.extra_ssh_options = options
            self.commands.clear()
            with self.assertRaisesRegex(access.Failure, 'ssh_policy'):
                self.configure()
            self.assertFalse(any(argv[0] in {'usermod', 'useradd', 'chpasswd'} for argv, _ in self.commands))

    def test_key_only_policy_has_project_keys_and_explicit_passwordless_sudo(self):
        self.effective_password = 'no'
        self.extra_ssh_options = 'authenticationmethods publickey\n'
        key = 'ssh-ed25519 ' + __import__('base64').b64encode(__import__('struct').pack('>I', 11) + b'ssh-ed25519' + __import__('struct').pack('>I', 32) + b'k' * 32).decode()
        self.configure(password_hash=None, keys=[key], sudo_passwordless=True)
        self.assertEqual(key + '\n', (self.root / 'home/wlanpi/.ssh/authorized_keys').read_text())
        self.assertEqual(0o600, (self.root / 'home/wlanpi/.ssh/authorized_keys').stat().st_mode & 0o777)
        self.assertIn((self.root / 'home/wlanpi/.ssh/authorized_keys', 1000, 1000), self.chowns)
        self.assertIn('NOPASSWD: ALL', (self.root / 'etc/cmprovision-image-access.sudoers').read_text())
if __name__ == '__main__':
    unittest.main()
