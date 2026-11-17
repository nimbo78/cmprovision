import contextlib
import fcntl
from pathlib import Path
import tempfile
import threading
import unittest

from acceptance_wait import wait_worker_idle


class AcceptanceWaitTest(unittest.TestCase):
    def test_terminal_state_does_not_mean_worker_lock_has_been_released(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'worker.lock'
            held = path.open('w')
            self.addCleanup(held.close)
            fcntl.flock(held, fcntl.LOCK_EX)
            @contextlib.contextmanager
            def lock():
                with path.open('r') as handle:
                    fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
                    yield
            with self.assertRaises(TimeoutError):
                wait_worker_idle(lock, BlockingIOError, timeout=.01, interval=.001)
            timer = threading.Timer(.02, lambda: fcntl.flock(held, fcntl.LOCK_UN))
            timer.start()
            try:
                wait_worker_idle(lock, BlockingIOError, timeout=1, interval=.001)
            finally:
                timer.join()

    def test_unexpected_failure_is_not_retried_as_lock_contention(self):
        @contextlib.contextmanager
        def broken():
            raise ValueError('invalid state')
            yield
        with self.assertRaises(ValueError):
            wait_worker_idle(broken, BlockingIOError)
