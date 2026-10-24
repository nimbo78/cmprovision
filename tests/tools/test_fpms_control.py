import importlib.util
from pathlib import Path
import pytest

spec = importlib.util.spec_from_file_location('fpms_control', Path(__file__).parents[2] / 'tools/fpms-control.py')
control = importlib.util.module_from_spec(spec)
spec.loader.exec_module(control)


@pytest.mark.parametrize('data', [None, [], {}, {'enabled': 'yes', 'open_on_start': False},
                                    {'enabled': 1, 'open_on_start': False},
                                    dict(enabled=True, open_on_start=False, command='stop')])
def test_config_fails_closed(data):
    with pytest.raises(ValueError):
        control.valid_config(data)


def test_data_is_requested_only_for_an_enabled_compatible_live_display():
    enabled = dict(enabled=True, open_on_start=False)
    cap = dict(protocol=1)
    beat = dict(protocol=1, at=100, hardware=True)
    assert control.desired(control.DEFAULTS, cap, beat, 101) == (False, 'off')
    assert control.desired(enabled, {}, beat, 101) == (False, 'not installed')
    assert control.desired(enabled, dict(protocol=2), beat, 101) == (False, 'incompatible')
    assert control.desired(enabled, cap, beat, 116) == (False, 'waiting for FPMS')
    assert control.desired(enabled, cap, dict(beat, at=200), 101) == (False, 'waiting for FPMS')
    assert control.desired(enabled, cap, dict(beat, hardware=False), 101) == (False, 'no hardware')
    assert control.desired(enabled, cap, beat, 101) == (True, 'running')


def test_shared_data_does_not_stop_with_hdmi():
    root = Path(__file__).parents[2] / 'debian'
    data = (root / 'cmprovision-wallboard-data.service').read_text()
    assert 'PartOf=' not in data
    assert 'StopWhenUnneeded=yes' in data
    for consumer in ['cmprovision-wallboard-display', 'cmprovision-fpms-data']:
        assert 'Wants=cmprovision-wallboard-data.service' in (root / (consumer + '.service')).read_text()
