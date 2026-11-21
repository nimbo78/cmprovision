"""Public port and device card contracts shared with the touch panel."""
import sys
import unittest
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'tools'))

import display_details as details
from hdmi_rotation import PassportRotation, passports


class CardDetailsTest(unittest.TestCase):
    def test_hardware_and_run_are_reported_not_assumed(self):
        sensor = dict(name="node", image="sensor.img", project="Factory", phase="write",
                      speed=2621440, eta=65, os_state="pending", hardware={"sku": "CM4104032 (inferred)"})
        self.assertIn(("SKU", "CM4104032 (inferred)"), details.hardware_rows(sensor))
        self.assertIn(("Image", "sensor.img"), details.run_rows(sensor))
        self.assertIn(("Rate", "2.5 MiB/s"), details.run_rows(sensor))
        self.assertIn(("ETA", "1:05"), details.run_rows(sensor))
        self.assertNotIn("ETA", dict(details.run_rows(dict(sensor, state="fail"))))
        self.assertFalse(details.has_run({"registered": False, "phase": "unregistered"}))
        self.assertFalse(details.has_run({"state":"recognized", "phase":"done"}))

    def test_diagnostics_include_when_and_how_and_the_next_action(self):
        sensor = {"diagnostics":{"source":"USB", "received_at":100, "issues":[
            {"title":"Storage missing", "advice":"Reseat the module"}]}}
        rows = details.diagnostic_rows(sensor, 165)
        self.assertIn(("Report", "USB · 1:05 ago"), rows)
        self.assertIn(("Next step", "Reseat the module"), rows)

    def test_physical_info_and_observations_leave_unreported_fields_empty(self):
        port = {"info":{"poe_class":0,"macs":["02:00:00:00:00:01"], "mac_count":3, "neighbors":[
            {"protocol":"lldp","name":"ap-lobby","address":"192.0.2.2","port":"eth0"}]}}
        self.assertIn(("PoE class", "0"), details.physical_rows(port, {"serial":"SW-A"}))
        self.assertIn(("Remote port", "eth0"), details.neighbor_rows(port))
        self.assertNotIn("RAM", dict(details.neighbor_rows(port)))
        self.assertNotIn("PoE class", dict(details.physical_rows({}, {})))

    def test_device_only_shows_the_matching_neighbors_management_address(self):
        port = {"info":{"neighbors":[{"name":"radio", "mac":"aa", "address":"192.0.2.1"},
                                     {"name":"radio", "port_mac":"bb", "address":"192.0.2.2"}]}}
        self.assertIn(("Management IP","192.0.2.1"), details.neighbor_rows(port))
        self.assertEqual("192.0.2.2", dict(details.observed_rows({"name":"radio", "mac":"bb"}, port))["Management IP"])
        self.assertNotIn("Management IP", dict(details.observed_rows({"name":"radio"}, port)))

    def test_rotation_visits_profiles_in_turn_and_interrupts_once_per_new_failure(self):
        cards = [dict(serial='A', state='ok'), dict(serial='B', state='run')]
        slides = PassportRotation()
        slides.observe(cards)
        self.assertIsNone(slides.take(cards, 0, 4))
        self.assertIsNone(slides.take(cards, 3, 4))
        self.assertEqual(0, slides.take(cards, 4, 4))
        self.assertIsNone(slides.take(cards, 4, 4))
        self.assertEqual(1, slides.take(cards, 8, 4))
        cards[1]['state'] = 'fail'
        slides.observe(cards)
        self.assertEqual(1, slides.take(cards, 8, 4))
        slides.observe(cards)
        self.assertIsNone(slides.take(cards, 8, 4))
        cards[1]['state'] = 'run'
        slides.observe(cards)
        cards[1]['state'] = 'fail'
        slides.observe(cards)
        self.assertEqual(0, slides.take(list(reversed(cards)), 9, 4))

    def test_only_ports_with_evidence_get_an_extra_profile_and_runs_stay_separate(self):
        ports = [dict(name="Gi0/1", port_id="p1", serials=["A"]),
                 dict(name="Gi0/2", port_id="p2", poe_state="delivering", link_up=False, server=True, endpoint="old"),
                 dict(name="Gi0/3", port_id="p3", poe_state="searching", state="free", station={"name":"old"})]
        data = {"modules":[dict(serial="A")], "switches":[dict(name="sw",blocks=[dict(ports=ports)])]}
        selected = passports(data)
        self.assertEqual(2, len(selected))
        self.assertEqual("PoE without link", selected[1]["name"])
        self.assertFalse(details.has_run(selected[1]))
        self.assertEqual([{"serial":"A"}], data["modules"])

    def test_simultaneous_failures_queue_once_and_a_disconnected_one_is_dropped(self):
        slides = PassportRotation()
        cards = [dict(serial=s, state="fail") for s in ("A","B","C")]
        slides.observe(cards)
        self.assertEqual(0, slides.take(cards, 0, 4))
        cards.pop(1)
        slides.observe(cards)
        self.assertEqual(1, slides.take(cards, 0, 4))
        self.assertIsNone(slides.take(cards, 0, 4))
