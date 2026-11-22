CREATE TABLE IF NOT EXISTS boards (
    slug TEXT PRIMARY KEY, title TEXT NOT NULL, description TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS threads (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    board_slug TEXT NOT NULL REFERENCES boards(slug),
    subject TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S', 'now')),
    bumped_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S', 'now')),
    locked INTEGER NOT NULL DEFAULT 0,
    pinned INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS posts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    thread_id INTEGER NOT NULL REFERENCES threads(id) ON DELETE CASCADE,
    body TEXT NOT NULL,
    image TEXT,
    image_name TEXT,
    image_width INTEGER,
    image_height INTEGER,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S', 'now'))
);
CREATE INDEX IF NOT EXISTS posts_thread ON posts(thread_id, id);
CREATE INDEX IF NOT EXISTS threads_board ON threads(board_slug, pinned, bumped_at);
CREATE TABLE IF NOT EXISTS rate_limits (
    key TEXT PRIMARY KEY, hits INTEGER NOT NULL, expires INTEGER NOT NULL
);
