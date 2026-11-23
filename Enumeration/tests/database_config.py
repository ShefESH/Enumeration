#!/usr/bin/env python3
"""Check installer password serialization against the real PHP config loader."""
import json
import os
from pathlib import Path
import shutil
import sqlite3
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]


class DatabaseConfigTests(unittest.TestCase):
    def test_passwords_round_trip_through_php_and_sql_literals(self):
        passwords = ['ordinary-password', "quote'and\"double", 'back\\slash\\tail\\',
                     '  spaces at both ends  ', '$dollar`backtick`!',
                     "x\\'; DROP USER 'forum'; --", 'café-数据库-🔑']
        env = {key: value for key, value in os.environ.items()
               if key not in ('FORUM_CONFIG', 'FORUM_DB_DSN', 'FORUM_DB_USER', 'FORUM_DB_PASSWORD')}
        code = '''
            require $argv[1];
            $password = stream_get_contents(STDIN);
            file_put_contents($argv[2] . '/config.php', installer_database_config($password));
            require $argv[2] . '/app/bootstrap.php';
            echo json_encode([config('FORUM_DB_PASSWORD'), config('FORUM_DB_USER'),
                              config('FORUM_DB_DSN'), installer_password_literal($password)]);
        '''
        with tempfile.TemporaryDirectory() as tmp, sqlite3.connect(':memory:') as sql:
            (Path(tmp) / 'app').mkdir()
            shutil.copy2(ROOT / 'app/bootstrap.php', Path(tmp) / 'app/bootstrap.php')
            for password in passwords:
                with self.subTest(password_case=passwords.index(password)):
                    result = subprocess.run(['php', '-r', code, str(ROOT / 'bin/install-db.php'), tmp],
                                            input=password, text=True, capture_output=True, check=True, env=env)
                    loaded, user, dsn, literal = json.loads(result.stdout)
                    self.assertEqual(loaded, password)
                    self.assertEqual(user, 'forum')
                    self.assertEqual(dsn, 'mysql:host=127.0.0.1;port=3306;dbname=enumeration;charset=utf8mb4')
                    # Standard SQL string rules also used by MySQL's NO_BACKSLASH_ESCAPES mode.
                    self.assertEqual(sql.execute('SELECT ' + literal).fetchone()[0], password)


if __name__ == '__main__':
    unittest.main()
