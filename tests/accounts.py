#!/usr/bin/env python3
"""Account, migration and avatar checks against disposable storage."""
import contextlib
import csv
import http.cookiejar
import io
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

class Client:
    def __init__(self, base):
        self.base = base
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))

    def request(self, path, fields=None, image=None):
        headers = {}
        data = None
        if image:
            boundary = 'account-test-upload'
            parts = [f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode() for k, v in fields.items()]
            parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="image"; filename="avatar.png"\r\nContent-Type: image/png\r\n\r\n'.encode() + image + b'\r\n')
            parts.append(f'--{boundary}--\r\n'.encode())
            data = b''.join(parts)
            headers['Content-Type'] = 'multipart/form-data; boundary=' + boundary
        elif fields is not None:
            data = urllib.parse.urlencode(fields).encode()
        try:
            result = self.opener.open(urllib.request.Request(self.base + path, data=data, headers=headers), timeout=10)
        except urllib.error.HTTPError as error:
            result = error
        with result:
            return result.status, result.read(), result.headers, result.url

    def text(self, path, fields=None, image=None):
        status, data, headers, url = self.request(path, fields, image)
        return status, data.decode(), headers, url

    def token(self, path):
        status, html, _, _ = self.text(path)
        assert status == 200, (status, html)
        return re.search(r'name="csrf" value="([a-f0-9]+)"', html).group(1)


def sql(directory, statement, values=()):
    with sqlite3.connect(directory / 'forum.sqlite') as con:
        return con.execute(statement, values).fetchall()


def image_png():
    def chunk(kind, data):
        return struct.pack('!I', len(data)) + kind + data + struct.pack('!I', zlib.crc32(kind + data))
    return b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('!2I5B', 2, 1, 8, 2, 0, 0, 0)) + chunk(b'IDAT', zlib.compress(b'\x00\xff\x00\x00\x00\xff\x00')) + chunk(b'IEND', b'')


@contextlib.contextmanager
def server():
    with tempfile.TemporaryDirectory(prefix='enumeration-account-test-') as temp:
        directory = Path(temp)
        env = dict(os.environ, FORUM_DATA_DIR=temp, FORUM_LAB_MODE='0')
        env.pop('FORUM_ADMIN_HASH', None)
        # Create a genuine pre-account schema and record to exercise in-place migration.
        schema = (ROOT / 'app/schema.sql').read_text()
        schema = re.sub(r'CREATE TABLE IF NOT EXISTS users \(.*?\);\n', '', schema, flags=re.S)
        schema = schema.replace('    account_id INTEGER REFERENCES users(id),\n', '')
        with sqlite3.connect(directory / 'forum.sqlite') as con:
            con.executescript(schema)
            con.execute("INSERT INTO boards VALUES ('gen', 'General', '')")
            con.execute("INSERT INTO threads (board_slug, subject) VALUES ('gen', 'Legacy thread')")
            con.execute("INSERT INTO anonymous_users (token_hash) VALUES ('legacy-test')")
            con.execute("INSERT INTO posts (thread_id,user_id,body) VALUES (1,1,'Legacy body')")
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        with tempfile.TemporaryFile() as log:
            proc = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', 'public', 'public/router.php'], cwd=ROOT, env=env, stdout=log, stderr=log)
            client = Client(f'http://127.0.0.1:{port}')
            try:
                for _ in range(60):
                    try:
                        if client.text('/')[0] == 200: break
                    except urllib.error.URLError: time.sleep(.05)
                else: raise AssertionError('Server did not start')
                yield client, directory, env
            finally:
                proc.terminate()
                proc.wait(timeout=10)
                log.seek(0)
                output = log.read().decode()
                assert 'PHP Fatal error' not in output and 'PHP Warning' not in output, output


def run():
    with server() as (alice, directory, env):
        assert sql(directory, 'SELECT body, user_id, account_id FROM posts WHERE id=1') == [('Legacy body', 1, None)]
        home = alice.text('/')[1]
        assert 'Server time:' in home and re.search(r'PHP \d+\.\d+', home) and re.search(r'SQLite \d+\.\d+', home)
        assert 'All times are UTC' not in home
        assert 'Legacy body' in alice.text('/gen/1')[1]
        assert alice.text('/account.php')[3].endswith('/login.php')
        token = alice.token('/register.php')
        password = '  account-test-password  '
        fields = dict(csrf=token, username='Test_User', password=password)
        assert alice.text('/register.php', dict(fields, csrf='bad'))[0] == 422
        for change in [dict(username='<script>'), dict(username='Anonymous'), dict(password='short'), dict(password='x'*73)]:
            assert alice.text('/register.php', dict(fields, **change))[0] == 422
        status, html, _, _ = alice.text('/register.php', fields)
        assert status == 200 and 'Logged in as' in html
        user_id, stored = sql(directory, 'SELECT id,password_hash FROM users WHERE username=?', ('Test_User',))[0]
        assert stored != password and stored.startswith('$2')
        token = alice.token('/account.php')
        assert token != fields['csrf']
        cookie = next(c for c in alice.jar if c.name == 'smallhours')
        assert 'HttpOnly' in cookie._rest
        assert 'Moderator sign-in' in alice.text('/admin.php')[1]
        assert alice.text('/account.php', dict(csrf='bad', action='logout'))[0] == 422
        assert 'Logged in as' in alice.text('/account.php?action=logout')[1]
        assert alice.text('/account.php', dict(csrf=token, action='logout'))[0] == 200
        assert alice.text('/account.php')[3].endswith('/login.php')
        token = alice.token('/login.php')
        assert alice.text('/login.php', dict(csrf=token, username='test_user', password=password.strip()))[0] == 422
        assert alice.text('/login.php', dict(csrf=token, username='test_user', password=password))[0] == 200
        token = alice.token('/account.php')
        print('PASS legacy migration, footer metadata, registration, password hashing, login, logout, session/CSRF rotation and moderator isolation')

        bob = Client(alice.base)
        sql(directory, 'DELETE FROM rate_limits')
        btoken = bob.token('/register.php')
        assert bob.text('/register.php', dict(csrf=btoken, username='TEST_USER', password=password))[0] == 422
        assert len(sql(directory, 'SELECT id FROM users')) == 1
        assert bob.text('/register.php', dict(csrf=btoken, username='Other_User', password=password))[0] == 200
        bob_id = sql(directory, 'SELECT id FROM users WHERE username=?', ('Other_User',))[0][0]
        for actor, expected_id in [(alice, user_id), (bob, bob_id)]:
            fields = dict(csrf=actor.token('/gen/'), board='gen', thread='1', body='Account-owned post', account_id=999)
            assert actor.text('/post.php', fields)[0] == 200
            assert sql(directory, 'SELECT account_id FROM posts ORDER BY id DESC LIMIT 1')[0][0] == expected_id
        guest = Client(alice.base)
        before = sql(directory, 'SELECT COUNT(*) FROM posts')[0][0]
        bumped = sql(directory, 'SELECT bumped_at FROM threads WHERE id=1')[0][0]
        files = list((directory / 'uploads').glob('*'))
        assert guest.text('/post.php', dict(csrf=guest.token('/gen/'), board='gen', thread=1, body='Guest reply', account_id=user_id), image_png())[0] == 403
        assert sql(directory, 'SELECT COUNT(*) FROM posts')[0][0] == before
        assert sql(directory, 'SELECT bumped_at FROM threads WHERE id=1')[0][0] == bumped
        assert list((directory / 'uploads').glob('*')) == files
        assert 'name="body"' not in guest.text('/gen/1')[1]
        assert 'register</a> to reply' in guest.text('/gen/1')[1]
        assert 'name="body"' in alice.text('/gen/1')[1]
        assert guest.text('/post.php', dict(csrf=guest.token('/gen/'), board='gen', subject='Guest thread', body='Guest opening post', account_id=user_id))[0] == 200
        assert sql(directory, 'SELECT account_id FROM posts ORDER BY id DESC LIMIT 1')[0][0] is None
        html = guest.text('/gen/1')[1]
        assert 'Test_User' in html and 'Other_User' in html and 'Anonymous' in html
        assert '/avatar.php?id=' in html
        board_html = guest.text('/gen/')[1]
        home_html = guest.text('/')[1]
        assert 'Test_User' not in board_html  # Starter of the legacy thread remains anonymous.
        assert 'Other_User' in board_html  # Latest registered reply.
        assert 'Latest posts' in home_html and 'Board statistics' in home_html
        assert '/gen/1#p3' in board_html and 'Replies' in board_html
        assert 'class="topic-row"' in board_html
        recent_html = home_html.split('<ul class="latest-posts">', 1)[1].split('</ul>', 1)[0]
        assert recent_html.count('<li>') == 2  # One latest post per thread, no duplicate activity rows.
        print('PASS case-insensitive unique usernames, registered/guest post ownership and author rendering')

        assert '<svg' in alice.text(f'/avatar.php?id={user_id}')[1]
        assert alice.text('/avatar.php?id=99999')[0] == 404
        assert alice.text('/avatar.php?id=../etc/passwd')[0] == 404
        assert guest.text('/account.php', dict(csrf=guest.token('/login.php'), action='avatar'), image_png())[3].endswith('/login.php')
        assert alice.text('/account.php', dict(csrf='bad', action='avatar'), image_png())[0] == 422
        assert alice.text('/account.php', dict(csrf=token, action='avatar'), b'<?php echo 1; ?>')[0] == 422
        assert alice.text('/account.php', dict(csrf=token, action='avatar'), image_png())[0] == 200
        old = sql(directory, 'SELECT avatar FROM users WHERE id=?', (user_id,))[0][0]
        status, image, headers, _ = alice.request(f'/avatar.php?id={user_id}')
        assert status == 200 and headers['Content-Type'] == 'image/png'
        assert struct.unpack('!II', image[16:24]) == (96, 96)
        assert sql(directory, 'SELECT avatar FROM users WHERE id=?', (bob_id,))[0][0] is None
        assert alice.text('/account.php', dict(csrf=token, action='avatar', id=bob_id), image_png())[0] == 200
        assert not (directory / 'uploads' / old).exists()
        new = sql(directory, 'SELECT avatar FROM users WHERE id=?', (user_id,))[0][0]
        assert alice.text('/account.php', dict(csrf=token, action='remove-avatar'))[0] == 200
        assert not (directory / 'uploads' / new).exists()
        assert '<svg' in alice.text(f'/avatar.php?id={user_id}')[1]
        print('PASS default avatars, upload authorization, PNG resizing, replacement, removal and file cleanup')

        before = sql(directory, 'SELECT COUNT(*) FROM posts')[0][0]
        seeded = []
        for count in [3, 2]:
            result = subprocess.run(['php', 'bin/seed-users.php', str(count)], cwd=ROOT, env=env, capture_output=True, text=True, check=True)
            rows = list(csv.DictReader(io.StringIO(result.stdout)))
            assert len(rows) == count
            seeded.extend(rows)
        assert len({r['username'] for r in seeded}) == 5
        assert len({r['password'] for r in seeded}) == 5
        assert sql(directory, 'SELECT COUNT(*) FROM posts')[0][0] == before
        assert len(sql(directory, 'SELECT id FROM users')) == 7
        assert subprocess.run(['php', 'bin/seed-users.php', '0'], cwd=ROOT, env=env, capture_output=True).returncode == 1
        status, html, _, url = guest.text('/login.php', dict(csrf=guest.token('/login.php'), next='/gen/1#compose', **seeded[0]))
        assert status == 200 and '/gen/1' in url and 'name="body"' in html
        redirect_test = Client(alice.base)
        status, _, _, url = redirect_test.text('/login.php', dict(csrf=redirect_test.token('/login.php'), next='https://example.com/', **seeded[1]))
        assert status == 200 and url == alice.base + '/account.php'
        signup = Client(alice.base)
        status, html, _, url = signup.text('/register.php', dict(csrf=signup.token('/register.php'), username='Reply_User', password=password, next='/gen/1#compose'))
        assert status == 200 and '/gen/1' in url and 'name="body"' in html
        old_token = signup.token('/gen/1')
        signup.text('/account.php', dict(csrf=old_token, action='logout'))
        assert signup.text('/post.php', dict(csrf=old_token, board='gen', thread=1, body='Stale login'))[0] == 403
        print('PASS guest reply denial without writes/uploads, login/register return paths, external redirect rejection and stale-session denial')
        print('PASS random user seeding, unique credentials, repeated runs, argument limits and seeded login')

        # Deleting an account session's database record must revoke its login.
        sql(directory, 'DELETE FROM users WHERE username=?', (seeded[0]['username'],))
        assert guest.text('/account.php')[3].endswith('/login.php')
        sql(directory, 'DELETE FROM rate_limits')
        fresh = Client(alice.base)
        token = fresh.token('/login.php')
        for _ in range(10):
            assert fresh.text('/login.php', dict(csrf=token, username='Test_User', password='wrong'))[0] == 422
        assert 'too fast' in fresh.text('/login.php', dict(csrf=token, username='Test_User', password=password))[1]
        print('PASS account revocation and login throttling')
        sql(directory, 'DELETE FROM posts')
        sql(directory, 'DELETE FROM threads')
        sql(directory, 'DELETE FROM users')
        empty_home = fresh.text('/')[1]
        assert empty_home.count('class="no-posts"') == 5
        assert '<p>No posts.</p>' in empty_home
        assert 'Newest member:' not in empty_home
        assert fresh.text('/gen/')[0] == 200
        assert 'No threads.' in fresh.text('/gen/')[1]
        print('PASS empty forum, latest-post cells and real-data statistics')
    print('All account checks passed.')

if __name__ == '__main__':
    run()
