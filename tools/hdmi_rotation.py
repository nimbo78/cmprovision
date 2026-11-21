"""Schedule one device passport after each map tour and foreground newly failed runs."""
from collections import deque
from display_details import entries


def passports(data):
    """Observed endpoints remain separate from run counts and the production queue."""
    cards = entries(data.get("modules")) + entries(data.get("recognized")) + entries(data.get("unknown"))
    occupied = {c.get("port_id") for c in cards if c.get("port_id")}
    for switch in entries(data.get("switches")):
        for block in entries(switch.get("blocks")):
            for port in entries(block.get("ports")):
                key = port.get("port_id")
                if not key or key in occupied or port.get("serials"):
                    continue
                name = port.get("endpoint")
                down = port.get("state") == "free" or port.get("link_up") is False
                if down:
                    if port.get("poe_state") != "delivering":
                        continue
                    name = None
                    kind = "PoE without link" if port.get("link_up") is False else "Powered port"
                elif port.get("server") or port.get("station"):
                    kind = "This station" if port.get("server") else "Other station"
                elif name:
                    kind = "Network neighbor"
                else:
                    continue
                cards.append(dict(port_id=key, port=f"{switch.get('name', '')} {port.get('name', '')}".strip(),
                                  name=name or kind, state="port", phase=kind, detail=kind, registered=False))
    return cards


def identity(card):
    return card.get("serial") or (card.get("port_id") or card.get("port"), card.get("mac") or card.get("name"))


class PassportRotation:
    def __init__(self):
        self.failures = set()
        self.pending = deque()
        self.last_tour = 0
        self.previous = None

    def observe(self, cards):
        current = {(identity(c), c.get("started_at")) for c in cards if c.get("state") == "fail"}
        self.pending = deque(event for event in self.pending if event in current)
        for card in cards:
            event = (identity(card), card.get("started_at"))
            if event in current and event not in self.failures and event not in self.pending:
                self.pending.append(event)
        self.failures = current

    def take(self, cards, tick, tour_length, periodic=True):
        keys = [identity(card) for card in cards]
        while self.pending:
            key, _ = self.pending.popleft()
            if key in keys:
                return keys.index(key)
        if not periodic or not keys or tick == 0 or tick % max(1, tour_length) or tick == self.last_tour:
            return None
        self.last_tour = tick
        index = (keys.index(self.previous)+1) % len(keys) if self.previous in keys else 0
        self.previous = keys[index]
        return index
