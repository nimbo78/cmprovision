"""Rescue radio policy. No subprocesses, filesystem access or wall-clock deadlines."""
import ipaddress
import re
import uuid

DEFAULTS = {'enabled': False, 'delay': 600, 'ssid': '', 'password': '', 'ap_mac': '',
            'client_mac': '', 'subnet': '', 'channel': 6, 'management_profiles': []}
MAC = re.compile(r'^(?:[0-9a-f]{2}:){5}[0-9a-f]{2}$')
STAND = ipaddress.ip_network('172.20.0.0/16')


def validate_config(data):
    if type(data) is not dict or set(data) - set(DEFAULTS):
        raise ValueError('Invalid rescue Wi-Fi settings.')
    result = dict(DEFAULTS, **data)
    if type(result['enabled']) is not bool or type(result['delay']) is not int or not 60 <= result['delay'] <= 1800:
        raise ValueError('Search timeout must be between 60 and 1800 seconds.')
    for key in ('ssid', 'password', 'ap_mac', 'client_mac', 'subnet'):
        if type(result[key]) is not str or any(ord(c) < 32 or ord(c) == 127 for c in result[key]):
            raise ValueError('Invalid rescue Wi-Fi text field.')
    if len(result['ssid'].encode('utf-8')) > 32 or result['enabled'] and not result['ssid']:
        raise ValueError('SSID must contain 1–32 UTF-8 bytes.')
    if result['password'] and (not 12 <= len(result['password']) <= 63 or not result['password'].isascii()):
        raise ValueError('Use a password of 12–63 printable ASCII characters.')
    if result['enabled'] and not result['password']:
        raise ValueError('A rescue Wi-Fi password is required.')
    for key in ('ap_mac', 'client_mac'):
        if (result[key] or result['enabled']) and not MAC.fullmatch(result[key]):
            raise ValueError('Select permanent Wi-Fi radio identities.')
    if result['ap_mac'] and result['ap_mac'] == result['client_mac']:
        raise ValueError('Access point and client must use different radios.')
    if type(result['channel']) is not int or result['channel'] not in (1, 6, 11):
        raise ValueError('Choose a permitted 2.4 GHz channel: 1, 6 or 11.')
    if result['subnet']:
        _subnet(result['subnet'])
    elif result['enabled']:
        raise ValueError('An access-point subnet is required.')
    profiles = result['management_profiles']
    if type(profiles) is not list or len(profiles) > 32 or any(type(v) is not str for v in profiles):
        raise ValueError('Invalid management profile selection.')
    try:
        if len(set(profiles)) != len(profiles) or any(str(uuid.UUID(v)) != v for v in profiles):
            raise ValueError()
    except (ValueError, AttributeError) as error:
        raise ValueError('Invalid management profile selection.') from error
    return result


def _subnet(value):
    try:
        network = ipaddress.ip_network(value, strict=True)
        private = [ipaddress.ip_network(v) for v in ('10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16')]
        if network.version != 4 or network.prefixlen != 24 or network.overlaps(STAND) or not any(
                network.subnet_of(parent) for parent in private):
            raise ValueError()
        return network
    except ValueError as error:
        raise ValueError('Choose a private /24 that does not overlap the flashing network.') from error


def choose_subnet(routes, requested=''):
    networks = [ipaddress.ip_network(value, strict=False) for value in routes]
    networks = [n for n in networks if n.version == 4 and n.prefixlen]
    candidates = [requested] if requested else [f'{base}.{i}.0/24' for base in ('192.168', '10.77') for i in range(240, 248)]
    for value in candidates:
        network = _subnet(value)
        if not any(network.overlaps(other) for other in networks):
            return str(network)
    raise ValueError('The access-point subnet overlaps another network; choose a different private /24.')


def select_radios(radios, ap_mac='', client_mac=''):
    """Resolve permanent identities; absence of a previously configured client is tolerated."""
    available = [r for r in radios if MAC.fullmatch(r.get('mac', ''))]
    if len({r['mac'] for r in available}) != len(available):
        raise ValueError('Wi-Fi radio identities are ambiguous.')
    ap = next((r for r in available if r['mac'] == ap_mac), None) if ap_mac else next(
        (r for r in available if r.get('bus') == 'sdio' and r.get('ap')), None)
    if ap is None or not ap.get('ap'):
        raise ValueError('The selected access-point radio is unavailable or does not support AP mode.')
    client = next((r for r in available if r['mac'] == client_mac), None) if client_mac else next(
        (r for r in available if r.get('phy') != ap.get('phy')), None)
    if not client_mac and client is None:
        raise ValueError('Two independent Wi-Fi radios are required.')
    if client_mac == ap['mac'] or client and client.get('phy') == ap.get('phy'):
        raise ValueError('Access point and client must use different physical radios.')
    return ap, client


def has_management(profiles, stand_device, management_profiles):
    for profile in profiles:
        if not profile.get('active') or profile.get('device') == stand_device or profile.get('mode') == 'ap':
            continue
        if profile.get('type') != 'wifi' and profile.get('uuid') not in management_profiles:
            continue
        for value in profile.get('addresses', []):
            try:
                address = ipaddress.ip_interface(value).ip
            except ValueError:
                continue
            if address.is_unspecified or address.is_loopback or address.is_multicast or address.is_link_local:
                continue
            if address.version == 4 and address not in STAND:
                return True
            if address.version == 6 and (address.is_global or address in ipaddress.ip_network('fc00::/7')):
                return True
    return False


def decide(config, observed, state, now, boot_id):
    """Return a next state and at most one effect. Observations describe actual NM ownership."""
    previous = state if state.get('boot_id') == boot_id else {}
    result = {'boot_id': boot_id, 'state': 'standby', 'reason': 'management_available', 'action': None,
              'remaining': None}
    for key in ('lost_since', 'healthy_since', 'other_since', 'idle_since', 'last_spare_attempt', 'hold_until',
                'pause_until', 'retry_after', 'failures'):
        value = previous.get(key)
        if type(value) in (int, float) and 0 <= value <= now + 3600:
            result[key] = value

    def output(label, reason, action=None, remaining=None):
        result.update(state=label, reason=reason, action=action, remaining=remaining)
        return result

    active = observed.get('ap_active', False)
    if observed.get('deferred'):
        return output('active' if active else 'waiting', 'station_operation_pending')
    if not config['enabled']:
        return output('off', 'disabled', 'stop' if active else None)
    if not observed.get('known'):
        for key in ('lost_since', 'healthy_since', 'idle_since'):
            result.pop(key, None)
        return output('active' if active else 'unavailable', 'observation_unavailable')
    if observed.get('blocked'):
        result.pop('lost_since', None)
        return output('unavailable', 'wifi_disabled')
    pause = result.get('pause_until', 0) - now
    if pause > 0:
        return output('paused', 'manual_pause', 'stop' if active else None, int(pause))
    healthy = observed.get('management', False)
    if healthy:
        result.pop('lost_since', None)
        result.setdefault('healthy_since', now)
    else:
        result.pop('healthy_since', None)
        result.pop('idle_since', None)
        result.setdefault('lost_since', now)
    if observed.get('other_management'):
        result.setdefault('other_since', now)
    else:
        result.pop('other_since', None)
    hold = result.get('hold_until', 0) - now
    if active:
        if not healthy or hold > 0:
            result.pop('idle_since', None)
            return output('active', 'manual_hold' if hold > 0 else 'management_unavailable',
                          remaining=int(hold) if hold > 0 else None)
        stable = now - result['healthy_since']
        if stable < 60:
            return output('recovery', 'waiting_for_stable_management', remaining=int(60 - stable))
        if observed.get('clients') != 0:
            result.pop('idle_since', None)
            return output('recovery', 'clients_connected' if observed.get('clients') is not None else 'clients_unknown')
        result.setdefault('idle_since', now)
        quiet = now - result['idle_since']
        if quiet < 60:
            return output('recovery', 'waiting_for_clients_to_leave', remaining=int(60 - quiet))
        return output('stopping', 'management_recovered', 'stop')
    if healthy and hold <= 0:
        if observed.get('other_management') and observed.get('spare_connected') and \
                now - result.get('other_since', now) >= 60 and observed.get('spare_enabled'):
            return output('standby', 'management_available', 'disconnect_spare')
        return output('standby', 'management_available')
    if not observed.get('ready'):
        return output('unavailable', observed.get('unavailable_reason', 'ap_not_ready'))
    if observed.get('radio_busy'):
        return output('unavailable', 'radio_busy')
    retry = result.get('retry_after', 0) - now
    if retry > 0:
        return output('fault', 'start_failed', remaining=int(retry))
    elapsed = now - result.get('lost_since', now)
    if hold > 0 or elapsed >= config['delay']:
        return output('starting', 'manual_start' if hold > 0 else 'search_timeout', 'start')
    if elapsed >= 30 and observed.get('spare_enabled') and not observed.get('spare_connected') and \
            now - result.get('last_spare_attempt', -60) >= 60:
        result['last_spare_attempt'] = now
        return output('waiting', 'trying_cold_spare', 'connect_spare', int(config['delay'] - elapsed))
    return output('waiting', 'searching_management', remaining=int(config['delay'] - elapsed))
