"""The provisioning server of the QEMU check (tools/utility-os/test.sh). The module's first /scriptexecute gets a 502 whose
page would leave a mark if the module ran it, the next ones the check script (check.sh with this server's address);
/samples/<name> serves compressed samples and ledpattern; POST /result keeps what the check found. Everything it sees
goes to the directory given first; test.sh stops it.
    server.py <out dir> <check.sh> <ledpattern>"""
import bz2
import gzip
import hashlib
import http.server
import lzma
import os
import random
import sys
import urllib.parse

OUT, CHECK, LEDPATTERN = sys.argv[1:4]


def samples():
    data = bytes(random.Random(2026).getrandbits(8) for _ in range(262144))
    files = {'garbage': b'<html><head><title>502 Bad Gateway</title></head><body>nginx</body></html>\n' * 8}
    with open(LEDPATTERN, 'rb') as f:
        files['ledpattern'] = f.read()
    packed = {'gz': gzip.compress(data), 'xz': lzma.compress(data, check=lzma.CHECK_CRC32), 'bz2': bz2.compress(data)}
    for kind, blob in packed.items():
        files['image.' + kind] = blob
        files['cut.' + kind] = blob[:len(blob) // 2]
    with open(os.path.join(OUT, 'image.sha256'), 'w') as f:
        f.write(hashlib.sha256(data).hexdigest() + '\n')
    return files


SAMPLES = samples()


class Handler(http.server.BaseHTTPRequestHandler):
    starts = 0

    def note(self, name, text):
        with open(os.path.join(OUT, name), 'a', encoding='utf-8') as f:
            f.write(text + '\n')

    def answer(self, code, body, kind='text/plain'):
        self.send_response(code)
        self.send_header('Content-Type', kind)
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        url = urllib.parse.urlsplit(self.path)
        self.note('requests', self.path)
        host = self.headers.get('Host', '')
        if url.path == '/scriptexecute':
            Handler.starts += 1
            if Handler.starts == 1:
                self.note('start', url.query)
                return self.answer(502, ('curl -s http://%s/ran-502\n' % host).encode())
            with open(CHECK, encoding='utf-8') as f:
                return self.answer(200, f.read().replace('__SERVER__', 'http://' + host).encode())
        if url.path == '/ran-502':
            self.note('ran-502', 'the module ran the page of a 502 as its script')
            return self.answer(200, b'')
        name = url.path[len('/samples/'):] if url.path.startswith('/samples/') else None
        if name in SAMPLES:
            return self.answer(200, SAMPLES[name], 'application/octet-stream')
        self.answer(404, b'not found\n')

    def do_POST(self):
        body = self.rfile.read(int(self.headers.get('Content-Length', '0')))
        if self.path == '/result':
            with open(os.path.join(OUT, 'result.txt'), 'wb') as f:
                f.write(body)
            return self.answer(200, b'')
        self.answer(404, b'')

    def log_message(self, fmt, *args):
        pass


httpd = http.server.HTTPServer(('127.0.0.1', 0), Handler)
with open(os.path.join(OUT, 'port'), 'w') as f:
    f.write(str(httpd.server_address[1]))
httpd.serve_forever()
