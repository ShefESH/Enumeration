# Enumeration

A PHP bulletin board with a classic forum layout, guest posting, optional username/password accounts, and avatars. No frontend dependencies.

- `/gen/`, `/tech/`, `/art/`, `/music/`, `/games/`, `/books/`, `/film/` boards.
- Anonymous browser IDs and message IDs, displayed with at least eight digits. Visitor IDs use an HttpOnly cookie and require no account.
- Board pages list threads with their author, reply count and latest post. `/gen/123` shows the entire conversation; replying requires login.
- Ten threads per board page, ordered by pinned status and latest activity.
- Optional image attachments, image-only replies, and subject search.
- `/admin.php` for password-protected moderation: pin, close/reopen, remove replies, and delete threads and their images.
- SQLite through PDO; database and images stay outside the public document root.
- An explicitly enabled SQL injection training mode suitable for SQLMap.

## Run locally

Requires **PHP 8.2+** with `pdo_sqlite`, `fileinfo`, `gd`, and `mbstring`. No Composer or Node installation needed.

```sh
# Optional: populate an empty forum with six friendly sample conversations.
php bin/seed.php

# Set the moderator password interactively (12+ characters).
php bin/admin.php

# Run from the repository root. Keep the document root set to public/.
php -d upload_max_filesize=4M -d post_max_size=6M -S 127.0.0.1:8080 -t public public/router.php
```

Open <http://127.0.0.1:8080>. The database initializes automatically. Without the optional seed step, boards start empty. Posting never requires an account. If no admin password is configured, moderator login is disabled; there is no default password. Password entry in the CLI is visible, so run it in a private terminal.

## Configuration

| Environment variable | Default | Purpose |
| --- | --- | --- |
| `FORUM_DATA_DIR` | `data/` in this checkout | Absolute path to writable private storage for SQLite, images, and the admin hash. |
| `FORUM_ADMIN_HASH` | Hash stored by `bin/admin.php` | Optional `password_hash()` value supplied by the environment. Overrides the file. |
| `FORUM_LAB_MODE` | off | Set to exactly `1` to enable the intentionally injectable search query. |

`data/` contents are gitignored. Do not put this directory beneath a web document root or commit real data/passwords. Back up the database using SQLite's backup API (or stop the server before copying the database and WAL files), together with the uploads directory. Server and CLI processes need access to the same storage.

## SQLMap training mode

**This is an intentionally vulnerable lab feature. Run it only on an isolated local instance with disposable data.** Normal mode uses a prepared statement. Enabling lab mode changes only the `q` query parameter in `/search.php` to direct SQL interpolation; it does not disable admin authentication or change posting queries.

```sh
FORUM_LAB_MODE=1 php -d upload_max_filesize=4M -d post_max_size=6M -S 127.0.0.1:8080 -t public public/router.php

# Run against your own local lab instance. Install SQLMap separately.
python3 /path/to/sqlmap/sqlmap.py \
  -u 'http://127.0.0.1:8080/search.php?board=gen&q=Welcome' \
  -p q --dbms=SQLite --technique=BU --batch --level=2
```

Seed the lab first so boolean-based tests have matching rows. Search does not require a session or CSRF token. Results are HTML-escaped, but that does not stop SQL injection. Malformed queries get a generic HTTP 400 response. Payloads are capped at 120 characters; there is deliberately no pretend SQL keyword filter. Restart without `FORUM_LAB_MODE=1` to restore the prepared search query. No attack target other than your local instance is necessary.

## Hosting

Use PHP-FPM or Apache with the **document root pointing to `public/`**, not the repository root. Apache needs `mod_rewrite` and `AllowOverride FileInfo Options` for the included `.htaccess`. For nginx, use a normal PHP-FPM configuration and route missing paths to the front controller:

```nginx
location / { try_files $uri $uri/ /index.php?$query_string; }
```

Configure PHP `upload_max_filesize=4M` and `post_max_size=6M`, a writable session directory, HTTPS, and private writable storage. The built-in PHP server is for local development. Keep lab mode disabled for any non-lab deployment.

## Behaviour and limits

- JPG, PNG, GIF, and WebP uploads: 4 MB input limit, at most 16 megapixels and 8,000 pixels per side. Images are decoded and saved as PNG with random names, removing embedded metadata and executable content. GIFs become still images. Encoded PNG files can be larger than the original input.
- Posting: 12 attempts per minute per source IP. Admin login: 5 attempts per five minutes per source IP. These limits use `REMOTE_ADDR`, not client-supplied proxy headers.
- Session-based CSRF protection on all mutations; escaped text; strict image serving; restrictive CSP. Admin sessions expire after two hours and are invalidated when the password changes.
- Removing the opening post requires deleting its thread. Deletions require an explicit checkbox, and remove associated stored images. Closing a thread prevents new replies.
- Registered posts show the account username and avatar. Guest posts contain no usernames or stored IP addresses. Posts display a stable browser ID; clearing the visitor cookie creates a new identity. Existing posts are assigned legacy IDs during migration because their original visitors were not recorded. Rate limits temporarily retain hashed IP-derived keys. The web server can still log visitor addresses; this is not an anonymity service.
- Small-community scope: no email/password recovery, notifications, private messaging, automatic content moderation, or upload storage quota. Moderators are responsible for keeping uploaded content suitable for everyone.

## Checks

```sh
find app public bin -name '*.php' -exec php -l {} \;
python3 tests/integration.py
```

The integration suite runs normal and lab servers against temporary storage and exercises posting, topic listings, search, injection behaviour, uploads, CSRF, moderation, rate limiting, and private-file routing. It does not touch your development database.

## User accounts and avatars

Register at `/register.php` with a username and password, then sign in at `/login.php`. Usernames are case-insensitive and allow 3–24 ASCII letters, numbers, or underscores. Passwords are preserved exactly (including spaces), require 10–72 bytes, and are stored with PHP's `password_hash()`. Login and registration are rate limited and CSRF protected. User sessions expire after 24 hours; accounts do not grant moderator access.

Guests can start threads; replying requires a registered account. The reply endpoint rejects unauthenticated submissions before processing uploads. Login and registration links on a thread return to its reply form after sign-in. Existing posts retain their anonymous identities; only new posts made while logged in are linked to an account. The schema upgrade is automatic and preserves existing threads and attachments.

At `/account.php`, upload or remove an avatar. Images use the existing 4 MB / 16-megapixel limits, are decoded and cropped to 96 × 96 PNGs, and are stored outside the public directory. Accounts without an upload use a generated identicon. Replacing or removing an avatar removes the old file.

The footer shows the current server time (including its timezone) at page render, PHP version, and the running SQLite version. Post timestamps remain UTC.

### Random users

```sh
# Optional: create 10 random accounts, without adding threads or posts.
php bin/seed-users.php 10

# Use isolated storage when creating test accounts.
FORUM_DATA_DIR=/tmp/enumeration-test-users php bin/seed-users.php 5
```

The count must be 1–100 (default 10). Each run adds unique random usernames and passwords; it does not change existing users. Credentials are printed once as CSV to stdout and should be kept private. Only hashes are stored in the database. Seeded users have default identicons and can log in normally.

Account checks use temporary storage:

```sh
python3 tests/accounts.py
```
