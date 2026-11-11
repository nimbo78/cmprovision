"""Local wall-clock schedules with a single durable UTC-independent slot per local date."""
from datetime import datetime, timedelta, timezone
import re
from zoneinfo import ZoneInfo, ZoneInfoNotFoundError

from update_manifest import UpdateError
from update_store import ID_RE, matches


def validate_schedule(value, profiles, now):
    fields = {'enabled', 'frequency', 'time', 'weekday', 'profile', 'keep', 'images', 'packages'}
    if type(value) is not dict or set(value) != fields:
        raise UpdateError('Invalid backup schedule fields.')
    if any(type(value[key]) is not bool for key in ('enabled', 'images', 'packages')):
        raise UpdateError('Invalid backup schedule switches.')
    if value['frequency'] not in ('daily', 'weekly') or not re.fullmatch(r'(?:[01][0-9]|2[0-3]):[0-5][0-9]', str(value['time'])):
        raise UpdateError('Choose a daily/weekly schedule and a valid 24-hour time.')
    if type(value['weekday']) is not int or not 0 <= value['weekday'] <= 6 or type(value['keep']) is not int or not 1 <= value['keep'] <= 365:
        raise UpdateError('Choose a weekday and retention from 1 to 365 successful copies.')
    if value['enabled'] and (not matches(ID_RE, value['profile']) or value['profile'] not in profiles):
        raise UpdateError('Choose a saved remote destination before enabling scheduled backups.')
    return dict(value, not_before=int(now))


def _candidate(config, date, zone):
    hour, minute = map(int, config['time'].split(':'))
    naive = datetime(date.year, date.month, date.day, hour, minute)
    original = naive
    # Missing spring wall times run once at the first valid minute after the gap.
    for _ in range(24*60+1):
        instant = naive.replace(tzinfo=zone, fold=0).timestamp()
        if datetime.fromtimestamp(instant, zone).replace(tzinfo=None) == naive:
            slot = '|'.join((config['frequency'], original.strftime('%Y-%m-%dT%H:%M'), zone.key, config['profile'] or ''))
            return int(instant), slot
        naive += timedelta(minutes=1)
    raise UpdateError('Could not resolve the scheduled local time.')


def _zone(name):
    try: return ZoneInfo(name)
    except (ValueError, ZoneInfoNotFoundError) as error: raise UpdateError('The system backup time zone is unavailable.') from error


def due(config, slots, now, zone):
    if not config['enabled']: return None
    zone = _zone(zone)
    local = datetime.fromtimestamp(now, zone)
    date = local.date()
    period = 1 if config['frequency'] == 'daily' else 7
    if period == 7: date -= timedelta(days=(date.weekday()-config['weekday']) % 7)
    instant, slot = _candidate(config, date, zone)
    if instant > now: instant, slot = _candidate(config, date-timedelta(days=period), zone)
    if instant < config.get('not_before', 0) or slot in slots: return None
    return slot


def next_run(config, now, zone):
    if not config['enabled']: return None
    zone = _zone(zone)
    date = datetime.fromtimestamp(now, zone).date()
    for offset in range(8):
        day = date+timedelta(days=offset)
        if config['frequency'] == 'weekly' and day.weekday() != config['weekday']: continue
        instant, _ = _candidate(config, day, zone)
        if instant > now and instant >= config.get('not_before', 0): return instant
    return None
