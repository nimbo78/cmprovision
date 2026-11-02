"""Validated NetworkManager IPv4/IPv6 profile values for the transaction controller.

This module builds argument lists only; it does not run NetworkManager or write a profile.
The caller must provide checkpoint, confirmation and recovery around any change.
"""
import ipaddress


class InvalidProfile(ValueError):
    pass


def ip(value, family, *, network=False):
    if not isinstance(value, str) or not value or '%' in value:
        raise InvalidProfile('An IP address has an invalid format.')
    try:
        result = ipaddress.ip_network(value, strict=False) if network else ipaddress.ip_address(value)
    except ValueError:
        raise InvalidProfile('An IP address has an invalid format.')
    if result.version != family:
        raise InvalidProfile('An IP address has the wrong address family.')
    if not network and (result.is_unspecified or result.is_multicast or result.is_loopback):
        raise InvalidProfile('An IP address is not a usable unicast address.')
    return str(result)


def cidr(value, family):
    if not isinstance(value, str) or '/' not in value or '%' in value:
        raise InvalidProfile('An interface address needs a prefix.')
    try:
        value = ipaddress.ip_interface(value)
    except ValueError:
        raise InvalidProfile('An interface address has an invalid format.')
    if value.version != family or value.ip.is_unspecified or value.ip.is_multicast or value.ip.is_loopback:
        raise InvalidProfile('An interface address is not usable in this address family.')
    return str(value)


def metric(value, label):
    if type(value) is not int or not -1 <= value <= 4294967295:
        raise InvalidProfile(label + ' must be -1 or a non-negative 32-bit integer.')
    return value


def family_profile(value, family):
    if not isinstance(value, dict):
        raise InvalidProfile('The IP configuration must be an object.')
    allowed = {'method', 'addresses', 'gateway', 'dns', 'routes', 'route_metric', 'never_default', 'ignore_auto_dns'}
    if set(value) - allowed or 'method' not in value:
        raise InvalidProfile('The IP configuration has missing or unknown fields.')
    methods = ('auto', 'manual', 'disabled', 'link-local') if family == 4 else ('auto', 'dhcp', 'manual', 'disabled', 'link-local', 'ignore')
    method = value['method']
    if method not in methods:
        raise InvalidProfile('Unsupported IP assignment method.')
    addresses = value.get('addresses', [])
    dns = value.get('dns', [])
    routes = value.get('routes', [])
    if not all(isinstance(items, list) and len(items) <= 32 for items in (addresses, dns, routes)):
        raise InvalidProfile('Addresses, DNS servers and routes must be short lists.')
    addresses = [cidr(item, family) for item in addresses]
    dns = [ip(item, family) for item in dns]
    gateway = value.get('gateway')
    gateway = '' if gateway is None or gateway == '' else ip(gateway, family)
    route_metric = metric(value.get('route_metric', -1), 'Route metric')
    never_default = value.get('never_default', False)
    ignore_auto_dns = value.get('ignore_auto_dns', False)
    if type(never_default) is not bool or type(ignore_auto_dns) is not bool:
        raise InvalidProfile('IP options must be true or false.')
    if method == 'manual' and not addresses:
        raise InvalidProfile('Manual IP assignment needs at least one address.')
    if method in ('disabled', 'link-local', 'ignore') and (addresses or gateway or dns or routes):
        raise InvalidProfile('Disabled or link-local IP cannot have manual settings.')
    if never_default and gateway:
        raise InvalidProfile('A gateway cannot be set when the default route is disabled.')
    formatted_routes = []
    for route in routes:
        if not isinstance(route, dict) or set(route) - {'destination', 'via', 'metric'} or 'destination' not in route:
            raise InvalidProfile('A route has missing or unknown fields.')
        destination = ip(route['destination'], family, network=True)
        if never_default and destination in ('0.0.0.0/0', '::/0'):
            raise InvalidProfile('A default route cannot be set when the default route is disabled.')
        via = route.get('via')
        via = '' if via is None or via == '' else ip(via, family)
        cost = route.get('metric', -1)
        metric(cost, 'Route metric')
        formatted_routes.append(' '.join(part for part in (destination, via, str(cost) if cost >= 0 else '') if part))
    prefix = 'ipv' + str(family) + '.'
    return [prefix + 'method', method, prefix + 'addresses', ','.join(addresses),
            prefix + 'gateway', gateway, prefix + 'dns', ','.join(dns), prefix + 'routes', ','.join(formatted_routes),
            prefix + 'route-metric', str(route_metric), prefix + 'never-default', 'yes' if never_default else 'no',
            prefix + 'ignore-auto-dns', 'yes' if ignore_auto_dns else 'no']


def properties(profile):
    if not isinstance(profile, dict) or not profile or set(profile) - {'ipv4', 'ipv6'}:
        raise InvalidProfile('Choose an IPv4 or IPv6 configuration.')
    arguments = []
    for family in (4, 6):
        key = 'ipv' + str(family)
        if key in profile:
            arguments.extend(family_profile(profile[key], family))
    return arguments
