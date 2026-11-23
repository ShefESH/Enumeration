"""Disposable MySQL databases, accessed through the application's PDO driver."""
import contextlib
import json
import os
from pathlib import Path
import subprocess
import uuid

ROOT = Path(__file__).resolve().parents[1]
ENVIRONMENTS = {}


def php_sql(env, statement, values=(), admin=False):
    code = """
    $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $pdo = new PDO(getenv('FORUM_DB_DSN'), getenv('FORUM_DB_USER'), getenv('FORUM_DB_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    $query = $pdo->prepare($input[0]);
    $query->execute($input[1]);
    echo json_encode($query->columnCount() ? $query->fetchAll(PDO::FETCH_NUM) : []);
    """
    result = subprocess.run(['php', '-r', code], input=json.dumps([statement, list(values)]),
                            text=True, capture_output=True, env=env, cwd=ROOT)
    if result.returncode:
        raise RuntimeError(result.stderr)
    return [tuple(row) for row in json.loads(result.stdout)]


@contextlib.contextmanager
def database(directory):
    # Explicitly separate admin test credentials from the configured application DB.
    admin = dict(os.environ,
        FORUM_DB_DSN=os.environ.get('FORUM_TEST_DSN', 'mysql:host=127.0.0.1;port=3306;charset=utf8mb4'),
        FORUM_DB_USER=os.environ.get('FORUM_TEST_USER', 'forum_test'),
        FORUM_DB_PASSWORD=os.environ.get('FORUM_TEST_PASSWORD', ''))
    name = 'enumeration_test_' + uuid.uuid4().hex
    php_sql(admin, f'CREATE DATABASE `{name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')
    env = dict(admin, FORUM_DB_DSN=admin['FORUM_DB_DSN'] + ';dbname=' + name,
               FORUM_DATA_DIR=str(directory), FORUM_LAB_MODE='0')
    env.pop('FORUM_ADMIN_HASH', None)
    ENVIRONMENTS[str(directory)] = env
    try:
        subprocess.run(['php', 'bin/init-db.php'], cwd=ROOT, env=env, check=True, capture_output=True)
        yield env
    finally:
        ENVIRONMENTS.pop(str(directory), None)
        php_sql(admin, f'DROP DATABASE `{name}`')


def sql(directory, statement, values=()):
    return php_sql(ENVIRONMENTS[str(directory)], statement, values)
