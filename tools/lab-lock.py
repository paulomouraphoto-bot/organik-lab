#!/usr/bin/env python3
"""Lock / unlock the lab's front page (index.html).

The repo is public, so the readable front page is never committed.
  Unlock to edit:  LAB_PASS=... python3 tools/lab-lock.py unlock   -> writes lab-index.plain.html (gitignored)
  Lock again:      LAB_PASS=... python3 tools/lab-lock.py lock     -> rebuilds index.html from it
Uses AES-GCM with a PBKDF2-SHA256 key (250k rounds), same as the Colorado hub.
"""
import base64, json, os, re, sys
from cryptography.hazmat.primitives.ciphers.aead import AESGCM
from cryptography.hazmat.primitives.kdf.pbkdf2 import PBKDF2HMAC
from cryptography.hazmat.primitives import hashes

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PLAIN = os.path.join(ROOT, 'lab-index.plain.html')
INDEX = os.path.join(ROOT, 'index.html')
TEMPLATE = os.path.join(ROOT, 'tools', 'login-template.html')
ITER = 250000

def key(pw, salt):
    return PBKDF2HMAC(algorithm=hashes.SHA256(), length=32, salt=salt, iterations=ITER).derive(pw.encode())

def main():
    pw = os.environ.get('LAB_PASS')
    if not pw or len(sys.argv) < 2: sys.exit(__doc__)
    if sys.argv[1] == 'lock':
        html = open(PLAIN, encoding='utf-8').read().encode()
        salt, iv = os.urandom(16), os.urandom(12)
        ct = AESGCM(key(pw, salt)).encrypt(iv, html, None)
        b = lambda x: base64.b64encode(x).decode()
        payload = json.dumps({'salt': b(salt), 'iv': b(iv), 'iter': ITER, 'ct': b(ct)})
        open(INDEX, 'w', encoding='utf-8').write(open(TEMPLATE, encoding='utf-8').read().replace('__PAYLOAD__', payload))
        print('locked index.html')
    elif sys.argv[1] == 'unlock':
        m = re.search(r'const P=(\{.*?\});', open(INDEX, encoding='utf-8').read())
        P = json.loads(m.group(1)); d = lambda k: base64.b64decode(P[k])
        open(PLAIN, 'w', encoding='utf-8').write(AESGCM(key(pw, d('salt'))).decrypt(d('iv'), d('ct'), None).decode())
        print('wrote lab-index.plain.html (do not commit)')

if __name__ == '__main__':
    main()
