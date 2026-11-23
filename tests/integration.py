#!/usr/bin/env python3
"""Exercise the real PHP HTTP endpoints using disposable databases."""
import contextlib
import http.cookiejar
import os
from pathlib import Path
import re
import socket
import sqlite3
import struct
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
import zlib

ROOT = Path(__file__).resolve().parents[1]
PASSWORD = 'integration-test-password'

class Client:
    def __init__(self, base):
        self.base = base
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))

    def request(self, path, fields=None, image=None):
        headers, data = {}, None
        if image:
            boundary = 'forum-integration-boundary'
            chunks = []
            for key, value in (fields or {}).items():
                chunks.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode())
            filename, content, mime = image
            chunks.append(f'--{boundary}\r\nContent-Disposition: form-data; name="image"; filename="{filename}"\r\nContent-Type: {mime}\r\n\r\n'.encode() + content + b'\r\n')
            chunks.append(f'--{boundary}--\r\n'.encode())
            data = b''.join(chunks)
            headers['Content-Type'] = 'multipart/form-data; boundary=' + boundary
        elif fields is not None:
            data = urllib.parse.urlencode(fields).encode()
        req = urllib.request.Request(self.base + path, data=data, headers=headers)
        try:
            response = self.opener.open(req, timeout=10)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            content = response.read()
            return response.status, content, response.headers, response.url

    def text(self, path, fields=None, image=None):
        code, data, headers, url = self.request(path, fields, image)
        return code, data.decode(), headers, url

    def csrf(self, path):
        code, body, _, _ = self.text(path)
        assert code == 200, (path, code)
        return re.search(r'name="csrf" value="([a-f0-9]+)"', body).group(1)

@contextlib.contextmanager
def server(lab=False):
    with tempfile.TemporaryDirectory(prefix='enumeration-test-') as directory:
        env = dict(os.environ, FORUM_DATA_DIR=directory, FORUM_LAB_MODE='1' if lab else '0')
        env.pop('FORUM_ADMIN_HASH', None)
        subprocess.run(['php', 'bin/seed.php'], cwd=ROOT, env=env, check=True, capture_output=True)
        subprocess.run(['php', 'bin/admin.php'], cwd=ROOT, env=env, input=PASSWORD+'\n', text=True, check=True, capture_output=True)
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        with tempfile.TemporaryFile() as log:
            proc = subprocess.Popen(['php', '-d', 'upload_max_filesize=4M', '-d', 'post_max_size=6M', '-S', f'127.0.0.1:{port}', '-t', 'public', 'public/router.php'], cwd=ROOT, env=env, stdout=log, stderr=log)
            client = Client(f'http://127.0.0.1:{port}')
            try:
                for _ in range(50):
                    try:
                        if client.text('/')[0] == 200:
                            break
                    except urllib.error.URLError:
                        time.sleep(.05)
                else:
                    raise AssertionError('Server did not start')
                yield client, Path(directory), env
            finally:
                proc.terminate()
                proc.wait(timeout=10)
                log.seek(0)
                logs = log.read().decode()
                assert 'PHP Fatal error' not in logs, logs


def sql(directory, statement, params=()):
    with sqlite3.connect(directory / 'forum.sqlite') as con:
        return con.execute(statement, params).fetchall()


def png():
    def chunk(kind, data):
        return struct.pack('!I', len(data)) + kind + data + struct.pack('!I', zlib.crc32(kind + data))
    return b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('!2I5B', 1, 1, 8, 2, 0, 0, 0)) + chunk(b'IDAT', zlib.compress(b'\x00\x80\x80\x80')) + chunk(b'IEND', b'')


def run():
    with server() as (client, directory, env):
        for path in ['/', '/gen/', '/tech/', '/art/', '/music/', '/games/', '/books/', '/film/', '/rules.php', '/admin.php']:
            assert client.text(path)[0] == 200, path
        for path in ['/missing/', '/gen/999999', '/tech/1', '/app/bootstrap.php', '/data/forum.sqlite', '/.git/config', '/assets/../../app/bootstrap.php']:
            assert client.text(path)[0] == 404, path
        assert client.text('/post.php')[0] == 405
        assert client.text('/image.php?file=../forum.sqlite')[0] == 404
        board = client.text('/gen/')[1]
        assert board.count('class="topic-row"') == 2
        assert 'class="topic-table"' in board and 'Last post' in board
        assert '/gen/1' in board and '/gen/2' in board
        assert 'Pinned:' in board
        assert '<article class="post ' not in board
        assert len(re.findall(r'<article class="post ', client.text('/gen/1')[1])) == 7
        thread_page = client.text('/gen/1')[1]
        assert re.search(r'No\.\d{8}', thread_page) and re.search(r'ID:\d{8}', thread_page)
        assert '\u00b7' not in board and '&dot;' not in board
        token = client.csrf('/gen/')
        fields = dict(csrf=token, board='gen', subject='Integration <script>alert(1)</script>', body='First <b>post</b>')
        assert client.text('/post.php', dict(fields, csrf='wrong'))[0] == 422
        assert client.text('/post.php', dict(fields, body='', subject='Empty'))[0] == 422
        code, body, _, url = client.text('/post.php', fields)
        assert code == 200 and '&lt;script&gt;' in body and '<script>' not in body
        tid = int(re.search(r'/gen/(\d+)', url).group(1))
        # Guests may open threads, but replies now require an account.
        assert client.text('/post.php', dict(csrf=token, board='gen', thread=tid, body='Guest reply'))[0] == 403
        assert client.text('/register.php', dict(csrf=client.csrf('/register.php'), username='Integration_User', password=PASSWORD))[0] == 200
        token = client.csrf('/gen/')
        for i in range(1, 7):
            assert client.text('/post.php', dict(csrf=token, board='gen', thread=tid, body=f'Reply {i}'))[0] == 200
        assert 'Reply 6' in client.text(f'/gen/{tid}')[1]
        assert 'Reply 6' not in client.text('/gen/')[1]
        users = sql(directory, 'SELECT DISTINCT user_id FROM posts WHERE thread_id=?', (tid,))
        assert len(users) == 1 and users[0][0] is not None
        other = Client(client.base)
        assert other.text('/register.php', dict(csrf=other.csrf('/register.php'), username='Second_User', password=PASSWORD))[0] == 200
        other_token = other.csrf('/gen/')
        assert other.text('/post.php', dict(csrf=other_token, board='gen', thread=tid, body='Another visitor'))[0] == 200
        assert len(sql(directory, 'SELECT DISTINCT user_id FROM posts WHERE thread_id=?', (tid,))) == 2
        assert 'HttpOnly' in str(next(c for c in client.jar if c.name == 'forum_visitor')._rest)
        print('PASS routes, topic tables, posting, escaping, CSRF, persistent padded visitor IDs')

        sql(directory, 'DELETE FROM rate_limits')
        upload = dict(csrf=token, board='art', subject='Image test', body='')
        assert client.text('/post.php', upload, ('bad.png', b'<?php echo 123; ?>', 'image/png'))[0] == 422
        assert client.text('/post.php', upload, ('large.png', b'x' * (4*1024*1024+1), 'image/png'))[0] == 422
        code, body, _, url = client.text('/post.php', upload, ('test.png', png(), 'image/png'))
        assert code == 200, body
        image_tid = int(re.search(r'/art/(\d+)', url).group(1))
        filename = sql(directory, 'SELECT image FROM posts WHERE thread_id=?', (image_tid,))[0][0]
        image_path = directory / 'uploads' / filename
        assert image_path.exists()
        code, image_data, headers, _ = client.request('/image.php?file=' + filename)
        assert code == 200 and headers['Content-Type'] == 'image/png' and image_data.startswith(b'\x89PNG')
        assert headers['X-Content-Type-Options'] == 'nosniff'
        print('PASS image upload, MIME/size rejection, private storage and image serving')

        assert client.text('/search.php?board=gen&q=Welcome')[0] == 200
        attack = "unlikely%' UNION SELECT 999,'injected-marker','gen','2005'-- "
        search_url = '/search.php?' + urllib.parse.urlencode(dict(board='gen', q=attack))
        assert '/gen/999' not in client.text(search_url)[1]
        assert client.text('/search.php?board=missing&q=x')[0] == 404
        print('PASS prepared search rejects UNION injection in normal mode')

        admin = Client(client.base)
        admin_token = admin.csrf('/admin.php')
        assert admin.text('/admin.php', dict(csrf=admin_token, action='lock', id=tid))[0] == 422
        assert sql(directory, 'SELECT locked FROM threads WHERE id=?', (tid,))[0][0] == 0
        assert admin.text('/admin.php', dict(csrf=admin_token, action='login', password='wrong'))[0] == 422
        assert admin.text('/admin.php', dict(csrf=admin_token, action='login', password=PASSWORD))[0] == 200
        admin_token = admin.csrf('/admin.php')
        assert admin.text('/admin.php', dict(csrf='bad', action='lock', id=tid))[0] == 422
        assert admin.text('/admin.php', dict(csrf=admin_token, action='lock', id=tid))[0] == 200
        assert client.text('/post.php', dict(csrf=token, board='gen', thread=tid, body='Closed reply'))[0] == 422
        assert admin.text('/admin.php', dict(csrf=admin_token, action='lock', id=tid))[0] == 200
        assert admin.text('/admin.php', dict(csrf=admin_token, action='pin', id=tid))[0] == 200
        assert sql(directory, 'SELECT pinned FROM threads WHERE id=?', (tid,))[0][0] == 1
        post_ids = [x[0] for x in sql(directory, 'SELECT id FROM posts WHERE thread_id=? ORDER BY id', (tid,))]
        assert admin.text('/admin.php', dict(csrf=admin_token, action='delete_post', id=post_ids[0], confirm='yes'))[0] == 422
        assert admin.text('/admin.php', dict(csrf=admin_token, action='delete_post', id=post_ids[1], confirm='yes', return_thread=tid))[0] == 200
        assert not sql(directory, 'SELECT id FROM posts WHERE id=?', (post_ids[1],))
        assert admin.text('/admin.php', dict(csrf=admin_token, action='delete_thread', id=image_tid))[0] == 422
        assert admin.text('/admin.php', dict(csrf=admin_token, action='delete_thread', id=image_tid, confirm='yes'))[0] == 200
        assert not image_path.exists()
        assert not sql(directory, 'SELECT id FROM posts WHERE thread_id=?', (image_tid,))
        # Changing the password invalidates an already signed-in moderator session.
        subprocess.run(['php', 'bin/admin.php'], cwd=ROOT, env=env, input='replacement-test-password\n', text=True, capture_output=True, check=True)
        assert 'Moderator sign-in' in admin.text('/admin.php')[1]
        print('PASS login, moderation, locked threads, deletion, file cleanup, session invalidation')

        sql(directory, 'DELETE FROM rate_limits')
        for _ in range(5):
            assert admin.text('/admin.php', dict(csrf=admin_token, action='login', password='wrong'))[0] == 422
        assert 'too fast' in admin.text('/admin.php', dict(csrf=admin_token, action='login', password='wrong'))[1]
        sql(directory, 'DELETE FROM rate_limits')
        for _ in range(12):
            client.text('/post.php', dict(csrf=token, board='gen', subject='Rate test', body=''))
        assert 'too fast' in client.text('/post.php', dict(csrf=token, board='gen', subject='Rate test', body=''))[1]
        print('PASS posting and login rate limits')

    with server(lab=True) as (client, directory, _):
        assert '/gen/999' in client.text(search_url)[1]
        yes = '/search.php?' + urllib.parse.urlencode(dict(board='gen', q="Welcome%' AND 1=1-- "))
        no = '/search.php?' + urllib.parse.urlencode(dict(board='gen', q="Welcome%' AND 1=2-- "))
        assert '/gen/2' in client.text(yes)[1]
        assert '/gen/2' not in client.text(no)[1]
        print('PASS intentional UNION and boolean SQL injection in lab mode')
    colours = set(re.findall(r'#[0-9a-fA-F]{6}\b', (ROOT/'public/assets/style.css').read_text()))
    assert len(colours) == 4, colours
    print('PASS exactly four CSS colours')
    print('All integration checks passed.')

if __name__ == '__main__':
    run()
