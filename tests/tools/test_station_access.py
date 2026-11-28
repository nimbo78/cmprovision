"""Authorization and filesystem contracts; no real Linux accounts are changed."""
import copy
from contextlib import nullcontext
import importlib.util
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
import sys
import tempfile
import unittest
from types import SimpleNamespace
from unittest.mock import patch

TOOLS = Path(__file__).resolve().parents[2] / 'tools'
sys.path.insert(0, str(TOOLS))


class Backend:
    def __init__(self):
        self.users = [{'user': 'operator', 'uid': 1000, 'gid': 1000, 'home': '/home/operator', 'shell': '/bin/bash'}]
        self.identity = self.users[0] | {'credential_revision': 'old'}
        self.applied = []
        self.has_password = True
        self.account_expired = False

    def status(self):
        return {'users': copy.deepcopy(self.users), 'ssh_active': True, 'password_login': True, 'key_login': True}

    def inspect(self, user, create_user=False, require_password=False, allow_password_login=False):
        if create_user:
            if any(account['user'] == user for account in self.users):
                raise ValueError('That username is already in use.')
            return {'user': user}
        if user != 'operator':
            raise ValueError('Choose an existing ordinary Linux administrator.')
        if allow_password_login and self.account_expired:
            raise ValueError('This Linux account is expired.')
        if require_password and not self.has_password:
            raise ValueError('Set a Linux password.')
        return copy.deepcopy(self.identity)

    def validate_keys(self, keys):
        if keys and not keys.startswith('ssh-ed25519 '):
            raise ValueError('Invalid SSH public key.')
        return keys.strip()

    def hash_password(self, password):
        return '$6$test$' + password[::-1]

    def apply(self, identity, changes):
        self.applied.append((identity, changes))
        return ['password'] if changes.get('password_hash') else ['keys']


class StationAccessTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name) / 'state'
        self.now = 1000
        self.backend = Backend()
        self.module = __import__('station_access')
        self.control = self.module.Controller(self.root, self.backend, clock=lambda: self.now)

    def prepare(self, **changes):
        return self.control.request({'action': 'prepare', 'user': 'operator', 'password': 'new-password-123',
                                     'keys': '', 'enable_ssh': False, **changes})

    def approved(self):
        plan = self.prepare()
        code = self.control.approve(plan['id'])['code']
        return plan, code

    def test_preparation_keeps_only_a_hash_and_safe_summary(self):
        plan = self.prepare()
        state = self.root.joinpath('pending.json').read_text()
        self.assertNotIn('new-password-123', state)
        self.assertNotIn('password_hash', json.dumps(plan))
        self.assertNotIn('code', json.dumps(plan))
        self.assertEqual([], self.backend.applied)
        self.assertEqual('operator', plan['user'])
        self.assertEqual(['Change Linux password'], plan['changes'])
        self.assertEqual(0o700, stat.S_IMODE(self.root.stat().st_mode))
        self.assertEqual(0o600, stat.S_IMODE((self.root / 'pending.json').stat().st_mode))

    def test_web_router_cannot_issue_or_read_a_code(self):
        plan, code = self.approved()
        for action in ['approve', 'issue', 'read-code']:
            with self.assertRaises(ValueError):
                self.control.request({'action': action, 'id': plan['id']})
        self.assertNotIn(code, json.dumps(self.control.request({'action': 'status'})))

    def test_valid_code_applies_exact_original_plan_once(self):
        plan, code = self.approved()
        result = self.control.request({'action': 'apply', 'id': plan['id'], 'code': code})
        self.assertEqual('applied', result['state'])
        self.assertEqual(1, len(self.backend.applied))
        self.assertEqual('$6$test$321-drowssap-wen', self.backend.applied[0][1]['password_hash'])
        with self.assertRaises(ValueError):
            self.control.request({'action': 'apply', 'id': plan['id'], 'code': code})
        self.assertNotIn('password_hash', (self.root / 'receipt.json').read_text())

    def test_payload_cannot_be_substituted_at_apply(self):
        plan, code = self.approved()
        with self.assertRaises(ValueError):
            self.control.request({'action': 'apply', 'id': plan['id'], 'code': code, 'user': 'root'})
        self.assertEqual([], self.backend.applied)

    def test_creation_and_password_login_are_separately_visible_at_local_approval(self):
        plan = self.prepare(user='newadmin', create_user=True, allow_password_login=True)
        self.assertEqual(['Create Linux administrator (sudo group)', 'Change Linux password',
                          'Allow SSH password login for this user'], self.control.review(plan['id'])['changes'])
        with self.assertRaises(ValueError):
            self.control.request({'action': 'apply', 'id': plan['id'], 'code': 'unapproved'})
        self.assertEqual([], self.backend.applied)
        code = self.control.approve(plan['id'])['code']
        self.control.request({'action': 'apply', 'id': plan['id'], 'code': code})
        self.assertEqual('newadmin', self.backend.applied[0][0]['user'])
        self.assertIs(self.backend.applied[0][1]['create_user'], True)
        self.assertIs(self.backend.applied[0][1]['allow_password_login'], True)

    def test_creation_rechecks_name_after_approval_and_never_adopts_existing_accounts(self):
        with self.assertRaises(ValueError):
            self.prepare(create_user=True)
        plan = self.prepare(user='newadmin', create_user=True)
        self.assertTrue(any('exclude this user from SSH' in change for change in self.control.review(plan['id'])['changes']))
        code = self.control.approve(plan['id'])['code']
        self.backend.users.append({'user': 'newadmin', 'uid': 1103})
        with self.assertRaises(ValueError):
            self.control.request({'action': 'apply', 'id': plan['id'], 'code': code})
        self.assertEqual([], self.backend.applied)
        self.assertIsNone(self.control.request({'action': 'status'})['pending'])

    def test_password_login_without_rotation_requires_a_usable_password(self):
        self.backend.has_password = False
        with self.assertRaises(ValueError):
            self.prepare(password='', allow_password_login=True)
        self.backend.has_password = True
        plan = self.prepare(password='', allow_password_login=True)
        self.assertEqual(['Allow SSH password login for this user'], plan['changes'])
        code = self.control.approve(plan['id'])['code']
        self.backend.has_password = False
        with self.assertRaises(ValueError):
            self.control.request({'action': 'apply', 'id': plan['id'], 'code': code})
        self.assertEqual([], self.backend.applied)

    def test_requesting_a_new_password_does_not_override_account_expiration(self):
        self.backend.account_expired = True
        with self.assertRaises(ValueError):
            self.prepare(allow_password_login=True)
        self.backend.account_expired = False
        plan = self.prepare(allow_password_login=True)
        self.backend.account_expired = True
        code = self.control.approve(plan['id'])['code']
        with self.assertRaises(ValueError):
            self.control.request({'action': 'apply', 'id': plan['id'], 'code': code})
        self.assertEqual([], self.backend.applied)

    def test_key_only_creation_is_allowed_without_a_password_exception(self):
        plan = self.prepare(user='newadmin', password='', keys='ssh-ed25519 QUJD', create_user=True)
        self.assertIn('Create Linux administrator (sudo group)', plan['changes'])
        self.assertNotIn('Allow SSH password login for this user', plan['changes'])

    def test_approval_does_not_accept_a_changed_summary_from_web(self):
        plan = self.prepare()
        with self.assertRaises(ValueError):
            self.control.request({'action': 'prepare', 'user': 'operator', 'password': 'other-password-123',
                                  'keys': '', 'enable_ssh': False})
        self.assertEqual(plan['id'], self.control.request({'action': 'status'})['pending']['id'])

    def test_five_wrong_attempts_revoke_the_operation(self):
        plan, code = self.approved()
        for _ in range(5):
            with self.assertRaises(ValueError):
                self.control.request({'action': 'apply', 'id': plan['id'], 'code': 'WRONG-CODE'})
        with self.assertRaises(ValueError):
            self.control.request({'action': 'apply', 'id': plan['id'], 'code': code})
        self.assertEqual([], self.backend.applied)

    def test_code_and_unapproved_plan_expire(self):
        plan, code = self.approved()
        self.now += 120
        with self.assertRaises(ValueError):
            self.control.request({'action': 'apply', 'id': plan['id'], 'code': code})
        self.assertEqual([], self.backend.applied)
        self.assertIsNone(self.control.request({'action': 'status'})['pending'])
        plan = self.prepare()
        self.now += 300
        with self.assertRaises(ValueError):
            self.control.approve(plan['id'])

    def test_concurrent_identity_or_password_change_invalidates_approval(self):
        plan, code = self.approved()
        self.backend.identity['credential_revision'] = 'changed elsewhere'
        with self.assertRaises(ValueError):
            self.control.request({'action': 'apply', 'id': plan['id'], 'code': code})
        self.assertEqual([], self.backend.applied)

    def test_cancel_removes_only_the_named_operation(self):
        plan = self.prepare()
        with self.assertRaises(ValueError):
            self.control.request({'action': 'cancel', 'id': '0' * 32})
        self.control.request({'action': 'cancel', 'id': plan['id']})
        self.assertIsNone(self.control.request({'action': 'status'})['pending'])

    def test_invalid_users_empty_changes_secret_delimiters_and_large_keys_rejected(self):
        cases = [{'user': 'root'}, {'user': '../operator'}, {'password': 'short'},
                 {'password': 'bad\npassword12'}, {'password': 'bad\x00password12'},
                 {'password': '', 'keys': '', 'enable_ssh': False}, {'keys': 'x' * 16385},
                 {'keys': 'command="evil" ssh-ed25519 key'}, {'enable_ssh': 'yes'},
                 {'create_user': 1}, {'allow_password_login': 'no'},
                 {'user': 'newadmin', 'password': '', 'create_user': True, 'enable_ssh': True},
                 {'user': 'newadmin', 'password': '', 'keys': 'ssh-ed25519 QUJD',
                  'create_user': True, 'allow_password_login': True}]
        for case in cases:
            with self.subTest(case=repr(case)[:70]), self.assertRaises(ValueError):
                self.prepare(**case)

    def test_pending_state_symlink_is_not_followed(self):
        self.root.mkdir(mode=0o700)
        target = Path(self.tmp.name) / 'target'
        target.write_text('sentinel')
        (self.root / 'pending.json').symlink_to(target)
        with self.assertRaises((ValueError, OSError)):
            self.prepare()
        self.assertEqual('sentinel', target.read_text())

    def test_backend_failure_consumes_code_and_preserves_a_safe_receipt(self):
        plan, code = self.approved()
        def fail(*args):
            raise RuntimeError('backend failure')
        self.backend.apply = fail
        with self.assertRaises(RuntimeError):
            self.control.request({'action': 'apply', 'id': plan['id'], 'code': code})
        with self.assertRaises(ValueError):
            self.control.request({'action': 'apply', 'id': plan['id'], 'code': code})
        self.assertEqual('failed', json.loads((self.root / 'receipt.json').read_text())['state'])


class AccountGuardsTest(unittest.TestCase):
    def setUp(self):
        from station_access_linux import Linux

        self.linux = Linux()
        directory = tempfile.TemporaryDirectory()
        self.addCleanup(directory.cleanup)
        self.linux.home_root = Path(directory.name)

    def test_new_name_must_be_unused_in_nss_groups_and_homes(self):
        name = 'cmp-fixture-user'
        with patch('pwd.getpwnam', side_effect=KeyError), patch('grp.getgrnam', side_effect=KeyError), \
                patch('grp.getgrall', return_value=[]):
            self.assertEqual({'user': name}, self.linux.inspect(name, create_user=True))
            (self.linux.home_root / name).symlink_to('/absent')
            with self.assertRaises(ValueError):
                self.linux.inspect(name, create_user=True)
            (self.linux.home_root / name).unlink()
            with patch('grp.getgrall', return_value=[SimpleNamespace(gr_mem=[name])]), self.assertRaises(ValueError):
                self.linux.inspect(name, create_user=True)
            with patch('grp.getgrnam', return_value=object()), self.assertRaises(ValueError):
                self.linux.inspect(name, create_user=True)
        for existing in ['root', 'www-data']:
            with self.subTest(existing=existing), self.assertRaises(ValueError):
                self.linux.inspect(existing, create_user=True)

    def test_existing_password_record_must_support_password_authentication(self):
        account = SimpleNamespace(pw_name='operator', pw_uid=1000, pw_gid=1000, pw_dir='/home/operator', pw_shell='/bin/bash')
        good_hash = '$6$salt$' + 'a' * 86
        with patch.object(self.linux, 'accounts', return_value=[account]):
            for invalid in ['', '!', '!!', '*', 'x', '!' + good_hash]:
                with self.subTest(shadow=invalid), patch.object(self.linux, 'run', return_value=f'operator:{invalid}:20000:0:99999:7:::'), self.assertRaises(ValueError):
                    self.linux.inspect('operator', require_password=True)
            for record in [f'operator:{good_hash}:20000:0:99999:7::10:', f'operator:{good_hash}:20000:0:1:7:::']:
                with patch.object(self.linux, 'run', return_value=record), self.assertRaises(ValueError):
                    self.linux.inspect('operator', require_password=True)
            with patch.object(self.linux, 'run', return_value=f'operator:{good_hash}:20000:0:99999:7:::'):
                self.assertEqual(1000, self.linux.inspect('operator', require_password=True)['uid'])

    def test_cleanup_never_removes_an_unexpected_account_after_creation(self):
        name = 'cmp-fixture-user'
        replaced = SimpleNamespace(pw_dir='/srv/existing-account', pw_name=name, pw_uid=1005, pw_gid=1005)
        commands = []
        def command(argv, data=None):
            commands.append(Path(argv[0]).name)
            return ''
        def inspect(user, create_user=False):
            if create_user:
                return {'user': user}
            raise ValueError('Account has changed')
        with patch.object(self.linux, 'inspect', inspect), patch.object(self.linux, 'run', command), \
                patch('pwd.getpwnam', return_value=replaced), self.assertRaises(ValueError):
            with self.linux.new_admin(name):
                self.fail('Unexpected account accepted')
        self.assertEqual(['useradd'], commands)

    def test_a_new_password_can_replace_an_expired_password_but_cannot_revive_an_expired_account(self):
        account = SimpleNamespace(pw_name='operator', pw_uid=1000, pw_gid=1000, pw_dir='/home/operator', pw_shell='/bin/bash')
        with patch.object(self.linux, 'accounts', return_value=[account]):
            with patch.object(self.linux, 'run', return_value='operator:!:20000:0:1:7::10:'), self.assertRaises(ValueError):
                self.linux.inspect('operator', allow_password_login=True)
            with patch.object(self.linux, 'run', return_value='operator:!:20000:0:1:7:::'):
                self.assertEqual(1000, self.linux.inspect('operator', allow_password_login=True)['uid'])

    def test_final_activation_rechecks_uid_gid_home_and_staged_password(self):
        identity = {'user': 'newadmin', 'uid': 1010, 'gid': 1010, 'home': '/home/newadmin', 'shell': '/bin/bash'}
        original = dict(pw_uid=1010, pw_gid=1010, pw_dir='/home/newadmin', pw_shell='/bin/bash')
        for changed, password in [({'pw_uid': 1020}, '*'), ({'pw_gid': 1020}, '*'),
                                  ({'pw_dir': '/srv/other'}, '*'), ({}, '!*')]:
            with self.subTest(changed=changed, password=password), \
                    patch('pwd.getpwnam', return_value=SimpleNamespace(**(original | changed))), \
                    patch.object(self.linux, 'shadow_record', return_value=['newadmin', password, '20000', '0', '99999', '7', '', '1', '']), \
                    patch.object(self.linux, 'run', side_effect=AssertionError('Unverified account was activated')), \
                    self.assertRaises(ValueError):
                self.linux.activate_admin(identity, '*')


@unittest.skipUnless(Path('/usr/sbin/sshd').is_file() and shutil.which('ssh-keygen') and Path('/run/sshd').is_dir(),
                     'OpenSSH server and its privilege separation directory are required')
class PasswordPolicyTest(unittest.TestCase):
    def setUp(self):
        from station_access_linux import Linux

        class FixtureLinux(Linux):
            def __init__(self, config):
                self.sshd_config = config
                self.reloaded = []
                self.fail_reload = False

            def reload_ssh(self):
                self.reloaded.append(self.sshd_config.read_bytes())
                if self.fail_reload:
                    self.fail_reload = False
                    raise RuntimeError('SSH reload failed')

            def run(self, argv, data=None):
                if argv[0] != '/usr/sbin/sshd':
                    raise AssertionError('Unexpected OS account or service operation')
                return super().run(argv, data)

        tmp = tempfile.TemporaryDirectory()
        self.addCleanup(tmp.cleanup)
        directory = Path(tmp.name)
        host_key = directory / 'hostkey'
        subprocess.run(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-f', str(host_key)], check=True)
        includes = directory / 'sshd_config.d'
        includes.mkdir()
        self.global_policy = includes / '00-cmprovision-access.conf'
        self.global_policy.write_text('PasswordAuthentication no\nKbdInteractiveAuthentication no\n')
        self.config = directory / 'sshd_config'
        self.config.write_text(f'Include {includes}/*.conf\nHostKey {host_key}\nUsePAM yes\n')
        self.linux = FixtureLinux(self.config)

    def allow(self):
        return self.linux.apply({'user': 'operator'}, {
            'keys': '', 'password_hash': '', 'enable_ssh': False, 'allow_password_login': True,
        })

    def policy(self, user):
        output = subprocess.check_output(['/usr/sbin/sshd', '-T', '-f', str(self.config), '-C',
                                          f'user={user},host=localhost,addr=127.0.0.1'], text=True)
        return dict(line.split(' ', 1) for line in output.splitlines() if ' ' in line)

    def test_key_only_image_allows_password_for_exactly_the_approved_user(self):
        initial = self.config.read_bytes()
        other = self.policy('other')
        root = self.policy('root')
        self.assertEqual('no', self.policy('operator')['passwordauthentication'])
        self.allow()
        self.assertEqual('yes', self.policy('operator')['passwordauthentication'])
        self.assertEqual('no', self.policy('operator')['kbdinteractiveauthentication'])
        self.assertEqual(root, self.policy('root'))
        self.assertEqual(other, self.policy('other'))
        self.assertTrue(self.config.read_bytes().startswith(initial))
        self.assertEqual([self.config.read_bytes()], self.linux.reloaded)
        first = self.config.read_bytes()
        self.allow()
        self.assertEqual(first, self.config.read_bytes())
        self.assertEqual('PasswordAuthentication no\nKbdInteractiveAuthentication no\n', self.global_policy.read_text())

    def test_previous_match_and_multiple_required_factors_remain_enforced(self):
        baseline = self.config.read_bytes()
        for rule in ['Match User operator\n    PasswordAuthentication no\n', 'AuthenticationMethods publickey,password\n']:
            with self.subTest(rule=rule):
                self.config.write_bytes(baseline + rule.encode())
                with self.assertRaises(ValueError):
                    self.allow()
                self.assertEqual(baseline + rule.encode(), self.config.read_bytes())
                self.assertEqual([], self.linux.reloaded)

    def test_reload_failure_restores_original_policy_on_disk_and_in_the_daemon(self):
        before = self.config.read_bytes()
        self.linux.fail_reload = True
        with self.assertRaises(RuntimeError):
            self.allow()
        self.assertEqual(before, self.config.read_bytes())
        self.assertEqual(before, self.linux.reloaded[-1])
        self.assertEqual('no', self.policy('operator')['passwordauthentication'])

    def test_refuses_to_write_through_a_main_config_symlink(self):
        target = self.config.with_name('linked-config')
        self.config.rename(target)
        self.config.symlink_to(target)
        before = target.read_bytes()
        with self.assertRaises((OSError, ValueError)):
            self.allow()
        self.assertEqual(before, target.read_bytes())
        self.assertTrue(self.config.is_symlink())
        self.assertEqual([], self.linux.reloaded)


class WithoutSshdTest(unittest.TestCase):
    def setUp(self):
        from station_access_linux import Linux

        tmp = tempfile.TemporaryDirectory()
        self.addCleanup(tmp.cleanup)
        self.root = Path(tmp.name)
        self.linux = Linux()
        self.linux.sshd_config = self.root / 'sshd_config'
        self.linux.sshd = self.root / 'absent-sshd'
        self.user = 'cmp-local-user'
        self.identity = {'user': self.user}
        self.changes = {'create_user': True, 'password_hash': 'fixture-hash', 'keys': '', 'enable_ssh': False}
        self.created = self.enterContext(patch.object(self.linux, 'new_admin', return_value=nullcontext(self.identity)))
        self.activated = self.enterContext(patch.object(self.linux, 'activate_admin'))
        self.commands = []
        def command(argv, data=None):
            self.commands.append((argv, data))
            if argv[0] != '/usr/sbin/chpasswd':
                raise AssertionError('A local password-only request tried to run ' + argv[0])
        self.enterContext(patch.object(self.linux, 'run', side_effect=command))
        self.guard = self.root / 'sshd_config.d' / ('00-cmprovision-local-' + self.user + '.conf')

    def test_local_password_works_and_future_debian_sshd_excludes_only_the_new_user(self):
        self.linux.apply(self.identity, self.changes)
        self.assertEqual([(['/usr/sbin/chpasswd', '-e'], self.user + ':fixture-hash\n')], self.commands)
        self.activated.assert_called_once_with(self.identity, 'fixture-hash')
        directives = [line for line in self.guard.read_text().splitlines() if line and not line.startswith('#')]
        self.assertEqual(['DenyUsers ' + self.user], directives)
        self.assertFalse(self.linux.sshd_config.exists())

    def test_explicit_ssh_operations_fail_before_staging_an_account(self):
        for option in [{'keys': 'ssh-ed25519 fixture'}, {'allow_password_login': True}, {'enable_ssh': True}]:
            with self.subTest(option=option), self.assertRaisesRegex(ValueError, 'openssh-server'):
                self.linux.apply(self.identity, self.changes | option)
        self.created.assert_not_called()
        self.assertEqual([], self.commands)
        self.assertFalse(self.guard.exists())

    def test_unverifiable_site_configuration_is_preserved_before_account_creation(self):
        self.guard.parent.mkdir()
        site = self.guard.parent / 'site.conf'
        for existing in (site, self.linux.sshd_config):
            with self.subTest(path=existing):
                existing.write_text('DenyUsers site-account\n')
                with self.assertRaisesRegex(ValueError, 'existing console'):
                    self.linux.apply(self.identity, self.changes)
                self.assertEqual('DenyUsers site-account\n', existing.read_text())
                existing.unlink()
        self.created.assert_not_called()
        self.assertFalse(self.guard.exists())

    def test_failed_account_creation_does_not_reserve_an_unused_name(self):
        self.activated.side_effect = RuntimeError('activation failed')
        with patch('pwd.getpwnam', side_effect=KeyError), self.assertRaisesRegex(RuntimeError, 'activation failed'):
            self.linux.apply(self.identity, self.changes)
        self.assertFalse(self.guard.exists())

    def test_local_account_stays_inactive_if_the_exclusion_changes_before_activation(self):
        def external_edit(argv, data):
            self.guard.write_text('DenyUsers site-account\n')
        self.linux.run.side_effect = external_edit
        with patch('pwd.getpwnam', side_effect=KeyError), self.assertRaisesRegex(ValueError, 'existing console'):
            self.linux.apply(self.identity, self.changes)
        self.activated.assert_not_called()
        self.assertEqual('DenyUsers site-account\n', self.guard.read_text())

    def existing_operator(self, server):
        account = SimpleNamespace(pw_name=self.user, pw_uid=1001, pw_gid=1001, pw_dir='/home/'+self.user, pw_shell='/bin/bash')
        self.enterContext(patch.object(server, 'accounts', return_value=[account]))
        self.enterContext(patch.object(server, 'shadow_record', return_value=[self.user, '$6$salt$' + 'a'*86, '20000', '0', '99999', '7', '', '', '']))

    def test_local_operator_approves_the_specific_exclusion_being_lifted(self):
        from station_access import Controller
        from station_access_linux import Linux

        self.linux.apply(self.identity, self.changes)
        server = Linux()
        server.sshd_config = self.linux.sshd_config
        self.existing_operator(server)
        control = Controller(self.root / 'state', server)
        plan = control.request({'action': 'prepare', 'user': self.user, 'password': '', 'keys': '',
                                'enable_ssh': False, 'allow_password_login': True})
        self.assertTrue(any('local-only SSH exclusion' in change for change in control.review(plan['id'])['changes']))
        code = control.approve(plan['id'])['code']
        self.guard.unlink()
        with self.assertRaisesRegex(ValueError, 'new request'):
            control.request({'action': 'apply', 'id': plan['id'], 'code': code})

    @unittest.skipUnless(Path('/usr/sbin/sshd').is_file() and shutil.which('ssh-keygen') and Path('/run/sshd').is_dir(),
                         'OpenSSH server and its privilege separation directory are required')
    def test_later_approved_password_login_releases_only_our_rule_and_a_failed_reload_restores_it(self):
        from station_access_linux import Linux

        self.linux.apply(self.identity, self.changes)
        original = self.guard.read_bytes()
        server = Linux()
        server.sshd_config = self.linux.sshd_config
        self.existing_operator(server)
        hostkey = self.root / 'hostkey'
        subprocess.run(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-f', str(hostkey)], check=True)
        server.sshd_config.write_text(f'Include {self.guard.parent}/*.conf\nHostKey {hostkey}\nPasswordAuthentication no\n')
        site = self.guard.parent / 'site.conf'
        site.write_text('DenyUsers site-account\n')
        def denied():
            effective = subprocess.check_output(['/usr/sbin/sshd', '-T', '-f', str(server.sshd_config)], text=True)
            return {name for line in effective.splitlines() if line.startswith('denyusers ') for name in line.split()[1:]}

        self.assertEqual({self.user, 'site-account'}, denied())
        grant = {'keys': '', 'enable_ssh': False, 'password_hash': '', 'allow_password_login': True}
        approved = server.inspect(self.user, allow_password_login=True)
        self.guard.write_bytes(original + b'# site-edited\n')
        with patch.object(server, 'reload_ssh'), self.assertRaisesRegex(ValueError, 'existing console'):
            server.apply(approved, grant)
        self.assertEqual(original + b'# site-edited\n', self.guard.read_bytes())
        self.guard.write_bytes(original)
        approved = server.inspect(self.user, allow_password_login=True)
        with patch.object(server, 'reload_ssh', side_effect=[RuntimeError('reload failed'), None]):
            with self.assertRaisesRegex(RuntimeError, 'reload failed'):
                server.apply(approved, grant)
        self.assertEqual(original, self.guard.read_bytes())
        self.assertEqual({self.user, 'site-account'}, denied())
        with patch.object(server, 'reload_ssh'):
            server.apply(server.inspect(self.user, allow_password_login=True), grant)
        self.assertEqual({'site-account'}, denied())
        self.assertEqual('yes', server.policy(self.user, server.sshd_config)['passwordauthentication'])
        self.assertEqual('no', server.policy('other', server.sshd_config)['passwordauthentication'])
        self.assertEqual('DenyUsers site-account\n', site.read_text())


class ServiceRollbackTest(unittest.TestCase):
    def station(self, unit_file, active, fail):
        from station_access_linux import Linux

        class ServiceLinux(Linux):
            sshd = Path('/bin/true')  # Service state is simulated even on test hosts without openssh-server.

            def __init__(self):
                self.unit_file = unit_file
                self.active = active

            def run(self, argv, data=None):
                if argv[0] == '/usr/sbin/chpasswd':
                    return ''
                if argv[0] == '/usr/bin/chage':
                    raise RuntimeError('Fixture activation failed')
                if argv[0] != '/bin/systemctl' or argv[-1] != 'ssh.service':
                    raise AssertionError(argv[0])
                action = argv[1]
                if action == 'show':
                    return 'UnitFileState=' + self.unit_file + '\nActiveState=' + ('active' if self.active else 'inactive')
                if action == 'enable':
                    self.unit_file = 'enabled-runtime' if '--runtime' in argv else 'enabled'
                    if '--now' in argv:
                        if fail == 'links':
                            raise RuntimeError('Fixture enabled unit links then failed')
                        self.active = True
                        if fail == 'started':
                            raise RuntimeError('Fixture started the service then failed')
                elif action == 'disable':
                    self.unit_file = 'disabled'
                elif action == 'stop':
                    self.active = False
                elif action == 'start':
                    self.active = True
                else:
                    raise AssertionError(action)
                return ''

            def new_admin(self, name):
                return nullcontext({'user': name})

            def activate_admin(self, identity, expected_hash):
                self.run(['/usr/bin/chage', '--expiredate', '-1', '--', identity['user']])

            def policy(self, user, config):
                return {}

            def prepare_sshd_runtime(self):
                pass

        return ServiceLinux()

    def test_service_link_and_running_state_are_independently_restored(self):
        for unit_file in ['disabled', 'enabled', 'enabled-runtime']:
            for active in [False, True]:
                for when in ['links', 'started', 'later']:
                    with self.subTest(unit_file=unit_file, active=active, when=when):
                        station = self.station(unit_file, active, when)
                        with self.assertRaises(RuntimeError):
                            station.apply({'user': 'operator'}, {
                                'create_user': True, 'password_hash': '', 'keys': '', 'enable_ssh': True,
                            })
                        self.assertEqual((unit_file, active), (station.unit_file, station.active))

    def test_successful_activation_persists_after_a_request(self):
        station = self.station('disabled', False, None)
        station.apply({'user': 'operator'}, {'password_hash': '', 'keys': '', 'enable_ssh': True})
        self.assertEqual(('enabled', True), (station.unit_file, station.active))


class UserKeysTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.home = Path(self.tmp.name)
        self.linux = __import__('station_access_linux').Linux

    def test_preserves_existing_restricted_keys_and_adds_once(self):
        folder = self.home / '.ssh'
        folder.mkdir(mode=0o700)
        path = folder / 'authorized_keys'
        original = 'restrict,command="/bin/true" ssh-ed25519 ORIGINAL old access'
        path.write_text(original)
        path.chmod(0o600)
        self.linux.write_user_keys(self.home, 'ssh-ed25519 NEW')
        self.linux.write_user_keys(self.home, 'ssh-ed25519 NEW')
        self.assertTrue(path.read_text().startswith(original + '\n'))
        self.assertEqual(1, path.read_text().count('ssh-ed25519 NEW'))

    def test_key_file_and_directory_symlinks_are_rejected_without_touching_target(self):
        folder = self.home / '.ssh'
        folder.mkdir(mode=0o700)
        target = self.home / 'sentinel'
        target.write_text('keep')
        path = folder / 'authorized_keys'
        path.symlink_to(target)
        with self.assertRaises((ValueError, OSError)):
            self.linux.write_user_keys(self.home, 'ssh-ed25519 NEW')
        self.assertEqual('keep', target.read_text())
        path.unlink()
        folder.rmdir()
        folder.symlink_to(self.home, target_is_directory=True)
        with self.assertRaises((ValueError, OSError)):
            self.linux.write_user_keys(self.home, 'ssh-ed25519 NEW')

    def test_hardlinks_world_writable_files_and_fifo_are_rejected(self):
        folder = self.home / '.ssh'
        folder.mkdir(mode=0o700)
        path = folder / 'authorized_keys'
        path.write_text('keep')
        path.chmod(0o600)
        os.link(path, self.home / 'hardlink')
        with self.assertRaises(ValueError):
            self.linux.write_user_keys(self.home, 'ssh-ed25519 NEW')
        (self.home / 'hardlink').unlink()
        path.chmod(0o666)
        with self.assertRaises(ValueError):
            self.linux.write_user_keys(self.home, 'ssh-ed25519 NEW')
        path.unlink()
        os.mkfifo(path)
        with self.assertRaises(ValueError):
            self.linux.write_user_keys(self.home, 'ssh-ed25519 NEW')


if __name__ == '__main__':
    unittest.main()
