#!/usr/bin/env python3
"""Prepare Netplan YAML for one Ethernet NIC, preserving other definitions."""
import copy
import fnmatch
import ipaddress
import json
from pathlib import Path
import subprocess
import sys


def matches(identifier, definition, name, link):
    match = definition.get('match')
    if match is None:
        return identifier == name
    if definition.get('set-name') == name:
        return True
    for key, pattern in match.items():
        value = {'name': name, 'macaddress': link['mac'], 'driver': link['driver']}.get(key)
        if value is None:
            raise ValueError(f'Unsupported interface match: {key}')
        patterns = pattern if isinstance(pattern, list) else [pattern]
        if not any(fnmatch.fnmatchcase(value.lower(), item.lower()) for item in patterns):
            return False
    return True


def configure(original, interface, links, default_routes, cidr, gateway, dns):
    config = copy.deepcopy(original)
    network = config.setdefault('network', {'version': 2})
    ethernets = network.setdefault('ethernets', {})
    candidates = []
    for identifier, definition in ethernets.items():
        if not isinstance(definition, dict):
            continue
        matched = [name for name, link in links.items() if matches(identifier, definition, name, link)]
        if interface in matched:
            if len(matched) > 1:
                raise ValueError(f'{identifier} matches multiple devices; give {interface} its own Netplan definition first.')
            candidates.append(identifier)
    if len(candidates) > 1:
        raise ValueError(f'Multiple Netplan definitions match {interface}: {candidates}')
    identifier = candidates[0] if candidates else interface
    if not candidates and identifier in ethernets:
        raise ValueError(f'Netplan ID {identifier} belongs to a different device.')
    for kind in ('bridges', 'bonds'):
        for name, definition in network.get(kind, {}).items():
            if identifier in definition.get('interfaces', []):
                raise ValueError(f'{interface} belongs to {kind}/{name}; select an independent Ethernet port.')
    definition = ethernets.setdefault(identifier, {})
    # Preserve IPv6 addresses, non-default routes, DNS search domains and the renderer.
    addresses = definition.get('addresses', [])
    definition['addresses'] = [address for address in addresses if ipaddress.ip_interface(
        next(iter(address)) if isinstance(address, dict) else address).version == 6] + [cidr]
    definition['dhcp4'] = False
    definition.pop('gateway4', None)
    metrics = [int(route.get('metric', 0)) for route in default_routes if route.get('dev') != interface]
    for kind in ('ethernets', 'wifis', 'bridges', 'bonds', 'vlans', 'tunnels'):
        for name, other in network.get(kind, {}).items():
            if not isinstance(other, dict) or (kind == 'ethernets' and name == identifier):
                continue
            metrics.extend(int(route.get('metric', 0)) for route in other.get('routes', [])
                           if route.get('to') in ('default', '0.0.0.0/0'))
    # Give existing management/default routes priority over the lab gateway.
    metric = max([500] + metrics) + 100
    definition['routes'] = [route for route in definition.get('routes', [])
                            if not (route.get('to') in ('default', '0.0.0.0/0')
                                    and ':' not in str(route.get('via', '')))]
    definition['routes'].append({'to': 'default', 'via': gateway, 'metric': metric})
    nameservers = definition.setdefault('nameservers', {})
    nameservers['addresses'] = [address for address in nameservers.get('addresses', [])
                               if ipaddress.ip_address(address).version == 6] + [dns]
    return config


def main():
    import yaml  # Installed explicitly alongside Netplan by install.sh.
    interface, cidr, gateway, dns = sys.argv[1:]
    original = yaml.safe_load(subprocess.check_output(['netplan', 'get'], text=True)) or {}
    links = {}
    for path in Path('/sys/class/net').iterdir():
        driver = path / 'device/driver'
        links[path.name] = {'mac': (path / 'address').read_text().strip(),
                            'driver': driver.resolve().name if driver.exists() else ''}
    routes = json.loads(subprocess.check_output(['ip', '-j', '-4', 'route', 'show', 'default'], text=True))
    result = configure(original, interface, links, routes, cidr, gateway, dns)
    print(yaml.safe_dump(result, sort_keys=False), end='')


if __name__ == '__main__':
    try:
        main()
    except ValueError as error:
        raise SystemExit(str(error)) from error
