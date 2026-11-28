"""Identify the Waveshare panel using the controller's bidirectional SPI data line."""


def identify(read_register):
    previous = None
    for _ in range(2):
        parts = [read_register(command, 1) for command in (0xDA, 0xDB, 0xDC)]
        combined = read_register(0x04, 4)
        pixel = read_register(0x0C, 1)
        if (any(not isinstance(p, (bytes, bytearray)) or len(p) != 1 for p in parts)
                or not isinstance(combined, (bytes, bytearray)) or len(combined) != 4
                or not isinstance(pixel, (bytes, bytearray)) or len(pixel) != 1):
            return None
        identity = b''.join(parts)
        if identity[0] != 0x7C or identity[1] < 0x80 or pixel[0] not in (3, 5, 6):
            return None
        # RDDID inserts a dummy clock before the three ID bytes.
        if ((int.from_bytes(combined, 'big') >> 7) & 0xFFFFFF) != int.from_bytes(identity, 'big'):
            return None
        if previous is not None and identity != previous:
            return None
        previous = identity
    return previous.hex()
