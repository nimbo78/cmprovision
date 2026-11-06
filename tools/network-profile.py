"""Validated NetworkManager IPv4/IPv6 profile values for the transaction controller.

This module builds argument lists only; it does not run NetworkManager or write a profile.
The caller must provide checkpoint, confirmation and recovery around any change.
"""
import ipaddress
import re


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


def unsigned(value, label, minimum=0):
    if type(value) is not int or not minimum <= value <= 4294967295:
        raise InvalidProfile(label + ' must be a 32-bit integer of at least ' + str(minimum) + '.')
    return value


def routing_rule(rule, family):
    allowed = {'priority', 'table', 'from', 'to', 'fwmark', 'fwmask', 'iif', 'oif'}
    if not isinstance(rule, dict) or set(rule) - allowed or not {'priority', 'table'} <= set(rule):
        raise InvalidProfile('A routing rule needs a priority and a table.')
    priority = unsigned(rule['priority'], 'Rule priority', 1)
    table = unsigned(rule['table'], 'Rule table', 1)
    if table == 255:
        raise InvalidProfile('The local routing table is managed by the system.')
    parts = ['priority', str(priority)]
    for key in ('from', 'to'):
        if rule.get(key):
            parts += [key, 'all' if rule[key] == 'all' else ip(rule[key], family, network=True)]
    if 'fwmask' in rule and 'fwmark' not in rule:
        raise InvalidProfile('A routing mark mask needs a mark.')
    if 'fwmark' in rule:
        mark = str(unsigned(rule['fwmark'], 'Packet mark'))
        if 'fwmask' in rule:
            mark += '/' + str(unsigned(rule['fwmask'], 'Packet mark mask'))
        parts += ['fwmark', mark]
    for key in ('iif', 'oif'):
        if key in rule:
            if not isinstance(rule[key], str) or not re.fullmatch(r'[A-Za-z0-9_.-]{1,15}', rule[key]):
                raise InvalidProfile('A rule interface name is invalid.')
            parts += [key, rule[key]]
    return ' '.join(parts + ['table', str(table)])


def route_attributes(route, family):
    parts = []
    for key in ('table', 'mtu'):
        if key in route:
            number = unsigned(route[key], 'Route ' + key, 1)
            if key == 'table' and number == 255:
                raise InvalidProfile('The local routing table is managed by the system.')
            if key == 'mtu' and not (1280 if family == 6 else 68) <= number <= 65535:
                raise InvalidProfile('The route MTU is outside the supported IP range.')
            parts.append(key + '=' + str(number))
    if 'src' in route:
        parts.append('src=' + ip(route['src'], family))
    if 'onlink' in route:
        if type(route['onlink']) is not bool:
            raise InvalidProfile('Route onlink must be true or false.')
        parts.append('onlink=' + ('true' if route['onlink'] else 'false'))
    if 'type' in route:
        if route['type'] not in ('unicast', 'blackhole', 'unreachable', 'prohibit', 'throw'):
            raise InvalidProfile('Unsupported route type.')
        if route['type'] != 'unicast' and (route.get('via') or route.get('onlink') or route.get('src')):
            raise InvalidProfile('A non-unicast route cannot have a gateway, preferred source or onlink.')
        parts.append('type=' + route['type'])
    return parts


def family_profile(value, family):
    if not isinstance(value, dict):
        raise InvalidProfile('The IP configuration must be an object.')
    allowed = {'method', 'addresses', 'gateway', 'dns', 'routes', 'route_metric', 'never_default', 'ignore_auto_dns',
               'route_table', 'routing_rules', 'ignore_auto_routes', 'dns_search'}
    if set(value) - allowed or 'method' not in value:
        raise InvalidProfile('The IP configuration has missing or unknown fields.')
    methods = ('auto', 'manual', 'disabled', 'link-local') if family == 4 else ('auto', 'dhcp', 'manual', 'disabled', 'link-local', 'ignore')
    method = value['method']
    if method not in methods:
        raise InvalidProfile('Unsupported IP assignment method.')
    addresses = value.get('addresses', [])
    dns = value.get('dns', [])
    routes = value.get('routes', [])
    rules = value.get('routing_rules', [])
    search = value.get('dns_search', [])
    if not all(isinstance(items, list) and len(items) <= 32 for items in (addresses, dns, routes, rules, search)):
        raise InvalidProfile('Addresses, DNS servers and routes must be short lists.')
    addresses = [cidr(item, family) for item in addresses]
    dns = [ip(item, family) for item in dns]
    gateway = value.get('gateway')
    gateway = '' if gateway is None or gateway == '' else ip(gateway, family)
    route_metric = metric(value.get('route_metric', -1), 'Route metric')
    never_default = value.get('never_default', False)
    ignore_auto_dns = value.get('ignore_auto_dns', False)
    ignore_auto_routes = value.get('ignore_auto_routes', False)
    if any(type(x) is not bool for x in (never_default, ignore_auto_dns, ignore_auto_routes)):
        raise InvalidProfile('IP options must be true or false.')
    route_table = unsigned(value.get('route_table', 0), 'Routing table')
    if route_table == 255:
        raise InvalidProfile('The local routing table is managed by the system.')
    for domain in search:
        if not isinstance(domain, str) or len(domain) > 254 or not re.fullmatch(r'~?(?:\.|[A-Za-z0-9_](?:[A-Za-z0-9_.-]*[A-Za-z0-9_.])?)', domain):
            raise InvalidProfile('A DNS search or routing domain is invalid.')
    if method == 'manual' and not addresses:
        raise InvalidProfile('Manual IP assignment needs at least one address.')
    if method in ('disabled', 'link-local', 'ignore') and (addresses or gateway or dns or routes or rules or search or route_table):
        raise InvalidProfile('Disabled or link-local IP cannot have manual settings.')
    if never_default and gateway:
        raise InvalidProfile('A gateway cannot be set when the default route is disabled.')
    formatted_routes = []
    for route in routes:
        if not isinstance(route, dict) or set(route) - {'destination', 'via', 'metric', 'table', 'mtu', 'src', 'onlink', 'type'} or 'destination' not in route:
            raise InvalidProfile('A route has missing or unknown fields.')
        destination = ip(route['destination'], family, network=True)
        if never_default and destination in ('0.0.0.0/0', '::/0'):
            raise InvalidProfile('A default route cannot be set when the default route is disabled.')
        via = route.get('via')
        via = '' if via is None or via == '' else ip(via, family)
        cost = route.get('metric', -1)
        metric(cost, 'Route metric')
        parts = [part for part in (destination, via, str(cost) if cost >= 0 else '') if part]
        formatted_routes.append(' '.join(parts + route_attributes(route, family)))
    formatted_rules = [routing_rule(rule, family) for rule in rules]
    if len({rule['priority'] for rule in rules}) != len(rules):
        raise InvalidProfile('Give each routing rule a distinct priority.')
    prefix = 'ipv' + str(family) + '.'
    arguments = [prefix + 'method', method, prefix + 'addresses', ','.join(addresses),
            prefix + 'gateway', gateway, prefix + 'dns', ','.join(dns), prefix + 'routes', ','.join(formatted_routes),
            prefix + 'route-metric', str(route_metric), prefix + 'never-default', 'yes' if never_default else 'no',
            prefix + 'ignore-auto-dns', 'yes' if ignore_auto_dns else 'no']
    # Older callers omit these fields. Do not silently erase existing policy routing or split DNS.
    for key, formatted in [('ignore_auto_routes', 'yes' if ignore_auto_routes else 'no'),
                           ('route_table', str(route_table)), ('routing_rules', ','.join(formatted_rules)),
                           ('dns_search', ','.join(search))]:
        if key in value:
            arguments += [prefix + key.replace('_', '-'), formatted]
    return arguments


def properties(profile):
    if not isinstance(profile, dict) or not profile or set(profile) - {'ipv4', 'ipv6'}:
        raise InvalidProfile('Choose an IPv4 or IPv6 configuration.')
    arguments = []
    for family in (4, 6):
        key = 'ipv' + str(family)
        if key in profile:
            arguments.extend(family_profile(profile[key], family))
    return arguments
