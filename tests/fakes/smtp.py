"""Tiny SMTP server for tests: AUTH LOGIN, no TLS. Appends every received message to OUT as one JSON line."""
import json, socketserver, sys, base64

OUT = sys.argv[2]
USER, PASS = 'mailer@example.com', 'secret'

class H(socketserver.StreamRequestHandler):
    def reply(self, s): self.wfile.write((s + '\r\n').encode()); self.wfile.flush()
    def handle(self):
        self.reply('220 fake ESMTP')
        msg = {'auth': None, 'from': None, 'to': [], 'data': ''}
        while True:
            line = self.rfile.readline().decode('utf-8', 'replace').rstrip('\r\n')
            if not line: return
            u = line.upper()
            if u.startswith('EHLO'): self.wfile.write(b'250-fake\r\n250 AUTH LOGIN\r\n'); self.wfile.flush()
            elif u == 'AUTH LOGIN':
                self.reply('334 VXNlcm5hbWU6'); user = base64.b64decode(self.rfile.readline().strip()).decode()
                self.reply('334 UGFzc3dvcmQ6'); pw = base64.b64decode(self.rfile.readline().strip()).decode()
                if (user, pw) == (USER, PASS): msg['auth'] = user; self.reply('235 ok')
                else: self.reply('535 bad credentials'); return
            elif u.startswith('MAIL FROM'): msg['from'] = line[10:].strip(); self.reply('250 ok')
            elif u.startswith('RCPT TO'): msg['to'].append(line[8:].strip()); self.reply('250 ok')
            elif u == 'DATA':
                self.reply('354 go'); buf = []
                while True:
                    l = self.rfile.readline().decode('utf-8', 'replace')
                    if l in ('.\r\n', '.\n', ''): break
                    buf.append(l[1:] if l.startswith('..') else l)  # undo dot-stuffing
                msg['data'] = ''.join(buf)
                with open(OUT, 'a') as f: f.write(json.dumps(msg) + '\n')
                self.reply('250 queued'); msg = {'auth': msg['auth'], 'from': None, 'to': [], 'data': ''}
            elif u == 'QUIT': self.reply('221 bye'); return
            else: self.reply('250 ok')

class S(socketserver.ThreadingTCPServer): allow_reuse_address = True; daemon_threads = True
S(('127.0.0.1', int(sys.argv[1])), H).serve_forever()
