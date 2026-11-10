"""Version selection for the disposable real-package update fixture."""
import re


def candidate_version(installed):
    match = re.fullmatch(r'([0-9]+)\.([0-9]+)\.([0-9]+)(?:[~+\-][0-9A-Za-z.+~\-]+)?', installed)
    if not match:
        raise ValueError('Unsupported installed version for the update acceptance fixture.')
    major, minor, patch = map(int, match.groups())
    return f'{major}.{minor}.{patch + 1}'
