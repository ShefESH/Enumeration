#!/usr/bin/env python3
"""Offline checks for targeted network configuration; never changes networking."""
import copy
from pathlib import Path
import runpy
import unittest

configure = runpy.run_path(str(Path(__file__).resolve().parents[1] / 'deploy/lab/configure-network.py'))['configure']
LINKS = {
    'enp1s0': {'mac': '00:11:22:33:44:01', 'driver': 'igb'},
    'enp2s0': {'mac': '00:11:22:33:44:02', 'driver': 'igb'},
    'wlan0': {'mac': '00:11:22:33:44:03', 'driver': 'iwlwifi'},
}


class NetworkConfigTests(unittest.TestCase):
    def update(self, config, routes=()):
        return configure(config, 'enp2s0', LINKS, routes, '192.168.50.2/24', '192.168.50.1', '192.168.50.1')

    def test_other_interfaces_renderer_and_source_are_preserved(self):
        original = {'network': {'version': 2, 'renderer': 'NetworkManager',
                    'ethernets': {'enp1s0': {'dhcp4': True}, 'enp2s0': {'dhcp4': True}},
                    'wifis': {'wlan0': {'dhcp4': True, 'access-points': {'example': {'password': 'test'}}}}}}
        before = copy.deepcopy(original)
        result = self.update(original)['network']
        self.assertEqual(original, before)
        self.assertEqual(result['renderer'], 'NetworkManager')
        self.assertEqual(result['ethernets']['enp1s0'], original['network']['ethernets']['enp1s0'])
        self.assertEqual(result['wifis'], original['network']['wifis'])
        self.assertFalse(result['ethernets']['enp2s0']['dhcp4'])
        self.assertEqual(result['ethernets']['enp2s0']['addresses'], ['192.168.50.2/24'])

    def test_existing_management_route_has_priority(self):
        original = {'network': {'version': 2, 'ethernets': {'enp1s0': {
            'routes': [{'to': 'default', 'via': '10.0.0.1', 'metric': 900}]}}}}
        result = self.update(original, [{'dev': 'wlan0', 'metric': 1200}])
        route = result['network']['ethernets']['enp2s0']['routes'][-1]
        self.assertEqual(route, {'to': 'default', 'via': '192.168.50.1', 'metric': 1300})

    def test_mac_based_definition_is_updated_in_place(self):
        original = {'network': {'version': 2, 'ethernets': {'lab': {
            'match': {'macaddress': LINKS['enp2s0']['mac']}, 'dhcp4': True}}}}
        result = self.update(original)['network']['ethernets']
        self.assertEqual(list(result), ['lab'])
        self.assertEqual(result['lab']['addresses'], ['192.168.50.2/24'])

    def test_ipv6_and_nondefault_routes_survive(self):
        ipv6_route = {'to': 'default', 'via': '2001:db8::1'}
        private_route = {'to': '10.5.0.0/16', 'via': '192.168.50.3'}
        original = {'network': {'version': 2, 'ethernets': {'enp2s0': {
            'addresses': ['10.0.0.2/24', '2001:db8::2/64'], 'dhcp6': True,
            'gateway4': '10.0.0.1',
            'routes': [{'to': '0.0.0.0/0', 'via': '10.0.0.1'}, ipv6_route, private_route],
            'nameservers': {'addresses': ['10.0.0.1', '2001:db8::53'], 'search': ['lab.local']}}}}}
        result = self.update(original)['network']['ethernets']['enp2s0']
        self.assertEqual(result['addresses'], ['2001:db8::2/64', '192.168.50.2/24'])
        self.assertTrue(result['dhcp6'])
        self.assertNotIn('gateway4', result)
        self.assertEqual(result['routes'][:2], [ipv6_route, private_route])
        self.assertEqual(result['nameservers'], {'addresses': ['2001:db8::53', '192.168.50.1'], 'search': ['lab.local']})

    def test_shared_wildcard_is_rejected(self):
        original = {'network': {'ethernets': {'all': {'match': {'name': 'enp*'}, 'dhcp4': True}}}}
        with self.assertRaisesRegex(ValueError, 'matches multiple devices'):
            self.update(original)

    def test_bridge_and_bond_members_are_rejected(self):
        for kind in ('bridges', 'bonds'):
            with self.subTest(kind=kind), self.assertRaisesRegex(ValueError, 'belongs to'):
                self.update({'network': {'ethernets': {'enp2s0': {}}, kind: {'group0': {'interfaces': ['enp2s0']}}}})

    def test_renamed_interface_keeps_existing_identity(self):
        original = {'network': {'ethernets': {'lab': {
            'match': {'name': 'eth1'}, 'set-name': 'enp2s0', 'dhcp4': True}}}}
        result = self.update(original)['network']['ethernets']
        self.assertEqual(list(result), ['lab'])
        self.assertEqual(result['lab']['set-name'], 'enp2s0')


if __name__ == '__main__':
    unittest.main()
