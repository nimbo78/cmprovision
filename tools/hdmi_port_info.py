"""Physical port readings for the graphical and text HDMI displays."""
import math


def industrial_columns(switch):
    """The publisher supplies socket positions in half-row units for a verified DIN panel."""
    if switch.get('layout') != 'industrial-columns':
        return False
    blocks = switch.get('blocks')
    if not isinstance(blocks, list) or len(blocks) != 2:
        return False
    for block, count in zip(blocks, (8, 4)):
        if not isinstance(block, dict) or block.get('rows') != count:
            return False
        ports = block.get('ports')
        if not isinstance(ports, list) or len(ports) != count:
            return False
        if any(not isinstance(p, dict) or type(p.get('panel_y')) is not int or
               not 0 <= p['panel_y'] <= 15 for p in ports):
            return False
    return True


def _positive(value):
    if isinstance(value, bool):
        return None
    try:
        number = float(value)
    except (TypeError, ValueError, OverflowError):
        return None
    return number if number > 0 and math.isfinite(number) else None


def link_reading(port):
    up = port.get('link_up')
    if up is not True:
        return ('Down', 'muted') if up is False else ('?', 'muted')
    speed = _positive(port.get('speed'))
    if speed is None:
        return 'Up', 'good'
    return (f'{speed / 1000:g}G' if speed >= 1000 else f'{speed:g}M'), 'good'


POWER_STATES = {
    'searching': ('Idle', 'muted'), 'disabled': ('Off', 'muted'),
    'fault': ('Fault', 'bad'), 'test': ('Test', 'warn'),
}


def power_reading(port):
    """Use PSE state first. Older switches sometimes report only measured watts."""
    state = port.get('poe_state')
    if isinstance(state, str) and state in POWER_STATES:
        return POWER_STATES[state]
    watts = _positive(port.get('watts'))
    if watts is not None:
        return f'{watts:g} W', 'good'
    return ('On', 'good') if state == 'delivering' else ('?', 'muted')


def _label(value):
    if not isinstance(value, str):
        return ''
    return ''.join(c if c.isprintable() else ' ' for c in value[:512]).strip()


def port_endpoint(port, host=None, *, modules=(), observed=None):
    """Use the publisher's port-local identity; reconstruct it only for older snapshots."""
    if 'endpoint' in port:
        return _label(port['endpoint'])
    if port.get('state') == 'free':
        return ''
    if port.get('state') == 'uplink':
        if port.get('server'):
            return _label(host) or 'This station'
        peer = port.get('station')
        if isinstance(peer, dict) and peer:
            return _label(peer.get('name') or peer.get('host')) or 'Other station'
    if modules:
        first = modules[0]
        primary = _label(first.get('hostname') or first.get('name') or first.get('mac') or first.get('serial'))
        if primary:
            return primary + (f' +{len(modules) - 1}' if len(modules) > 1 else '')
    if port.get('state') in ('recognized', 'unrecognized', 'quiet') and isinstance(observed, dict):
        return _label(observed.get('name') or observed.get('mac'))
    return ''
