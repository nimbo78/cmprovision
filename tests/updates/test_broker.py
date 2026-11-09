import json
import unittest
from unittest.mock import Mock

from support import *
from update_manifest import UpdateError
from update_broker import Broker, decode_request


class BrokerTest(unittest.TestCase):
    def test_bounded_json_has_no_ambiguous_or_unknown_protocol(self):
        for raw in (b'{}', b'{"action":"status","action":"check"}', b'[]', b'x'*8193,
                    b'{"action":"status","path":"/etc/shadow"}', b'{"action":"cancel","id":"../../x"}'):
            with self.subTest(raw=raw[:50]):
                with self.assertRaises(UpdateError): decode_request(raw)
        self.assertEqual({'action': 'status'}, decode_request(b'{"action":"status"}'))

    def test_reply_loss_cannot_launch_a_second_worker(self):
        store = Mock()
        store.begin.side_effect = [({'id': 'a'*32}, True), ({'id': 'a'*32}, False)]
        store.snapshot.return_value = {'schema': 1}
        launcher = Mock()
        broker = Broker(store, launcher)
        request = {'action': 'check', 'nonce': 'f'*64, 'components': ['application']}
        self.assertEqual('a'*32, broker.request(request)['id'])
        self.assertEqual('a'*32, broker.request(request)['id'])
        launcher.assert_called_once_with('a'*32)


if __name__ == '__main__': unittest.main()
