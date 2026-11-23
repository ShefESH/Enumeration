#!/usr/bin/env python3
"""An HTTP banner for the service-discovery challenge; serves no local files."""
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import os


class Handler(BaseHTTPRequestHandler):
    server_version = 'SHEFESH{secret_service}'
    sys_version = ''

    def do_GET(self):
        body = b'SHEFESH{secret_service}\n'
        self.send_response(200)
        self.send_header('Content-Type', 'text/plain; charset=utf-8')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_HEAD(self):
        self.send_response(200)
        self.send_header('Content-Type', 'text/plain; charset=utf-8')
        self.send_header('Content-Length', str(len(b'SHEFESH{secret_service}\n')))
        self.end_headers()


if __name__ == '__main__':
    ThreadingHTTPServer(('0.0.0.0', int(os.environ['SECRET_PORT'])), Handler).serve_forever()
