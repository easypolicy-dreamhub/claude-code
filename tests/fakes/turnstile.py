"""Stands in for Cloudflare's siteverify: success only for response=good-token."""
import json, sys, urllib.parse
from http.server import BaseHTTPRequestHandler, HTTPServer

class H(BaseHTTPRequestHandler):
    def do_POST(self):
        body = urllib.parse.parse_qs(self.rfile.read(int(self.headers.get('Content-Length', 0))).decode())
        ok = body.get('response') == ['good-token'] and body.get('secret') == ['test-secret']
        out = json.dumps({'success': ok}).encode()
        self.send_response(200); self.send_header('Content-Type', 'application/json'); self.end_headers(); self.wfile.write(out)
    def log_message(self, *a): pass

HTTPServer(('127.0.0.1', int(sys.argv[1])), H).serve_forever()
