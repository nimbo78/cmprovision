"""The terminal state is published before a worker finishes its final cleanup."""
import time


def wait_worker_idle(lock, busy_error, timeout=120, interval=.1):
    deadline = time.monotonic() + timeout
    while True:
        try:
            with lock():
                return
        except busy_error:
            if time.monotonic() >= deadline:
                raise TimeoutError('Recovery reached a terminal state but its worker did not release the lock.') from None
            time.sleep(interval)
