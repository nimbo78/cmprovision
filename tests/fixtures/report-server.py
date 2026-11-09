"""Loopback-only HTTP fixture for real curl report delivery tests."""
import http.server
import json
from pathlib import Path
import sys

statuses = [int(value) for value in sys.argv[1].split(',')]
requests = Path(sys.argv[2])


class Handler(http.server.BaseHTTPRequestHandler):
    count = 0

    def do_GET(self):
        type(self).count += 1
        requests.write_text(json.dumps({'count': self.count, 'run': self.headers.get('X-Cmprovision-Run')}))
        self.send_response(statuses[min(self.count - 1, len(statuses) - 1)])
        self.send_header('Retry-After', '0')
        self.end_headers()
        self.wfile.write(b'report response')

    def log_message(self, *args):
        pass


with http.server.HTTPServer(('127.0.0.1', 0), Handler) as server:
    print(server.server_port, flush=True)
    server.serve_forever()
