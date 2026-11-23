# Enumeration

A PHP forum and enumeration lab using **MySQL 8.0+**, PHP 8.2+, and Apache2. Guest threads, registered replies, avatars, image uploads, and moderator controls are included. The setup below targets a fresh **Ubuntu Server 24.04 LTS** VM and installs the checkout at `/var/www/html`.

Use a private lab network with disposable data for the optional SQL injection challenge and extra services. The decoy files contain synthetic content only.

## 1. Install Apache2, PHP, and MySQL

```bash
sudo apt update
sudo apt install -y apache2 mysql-server php libapache2-mod-php php-cli php-mysql php-gd php-mbstring git
sudo systemctl enable --now apache2 mysql
php -v
php -m | grep -E 'PDO|pdo_mysql|gd|mbstring|fileinfo'
```

These packages enable PHP through Apache's mod_php module. See Ubuntu's [PHP installation guide](https://ubuntu.com/server/docs/how-to/web-services/install-php/) and [MySQL installation guide](https://ubuntu.com/server/docs/how-to/databases/install-mysql/).

On a fresh server, clone into a temporary directory and copy the project, including its dotfiles:

```bash
git clone https://github.com/ShefESH/Enumeration.git /tmp/enumeration-source
sudo cp -a /tmp/enumeration-source/. /var/www/html/
cd /var/www/html
# Remove the stock Apache welcome page on this fresh server.
sudo rm -f /var/www/html/index.html
sudo chown -R root:root /var/www/html
sudo install -d -o www-data -g www-data -m 700 /var/www/html/data
```

For an existing checkout, update it instead of cloning over it. Keep all existing files in `data/`.

## 2. Create the MySQL database and account

Open MySQL's local administrative console:

```bash
sudo mysql
```

Run this SQL, replacing the password with a new password of your own:

```sql
CREATE DATABASE enumeration CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'forum'@'127.0.0.1' IDENTIFIED BY 'REPLACE_WITH_YOUR_DATABASE_PASSWORD';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, REFERENCES ON enumeration.* TO 'forum'@'127.0.0.1';
EXIT;
```

The account connects over TCP to `127.0.0.1:3306`. Leave MySQL bound to loopback; the forum does not need remote database access.

```bash
cd /var/www/html
sudo install -o root -g www-data -m 640 config.example.php config.php
sudo nano config.php
```

Set `FORUM_DB_PASSWORD` in `config.php` to the password from the SQL command. The file is outside `public/` and is gitignored. Both Apache and CLI commands read it. Initialize the schema:

```bash
sudo -u www-data php bin/init-db.php
```

Initialization can be repeated without dropping existing rows. MySQL tables use InnoDB, foreign keys, `utf8mb4`, and UTC timestamps. Account names remain case-insensitive. Web requests do not create or alter tables.

For a **fresh database**, optionally create sample content and accounts:

```bash
sudo -u www-data php bin/seed.php
sudo -u www-data php bin/seed-users.php 10
sudo -u www-data php bin/admin.php
```

The user seeder prints random credentials once as CSV; keep them private. The moderator password must be at least 12 characters and its interactive input is visible. Without a moderator password, moderator login is disabled.

After initialization (and any import), remove the account's schema permissions:

```bash
sudo mysql -e "REVOKE CREATE, REFERENCES ON enumeration.* FROM 'forum'@'127.0.0.1';"
```

## 3. Run the forum through Apache2

```bash
cd /var/www/html
sudo install -m 644 deploy/apache/enumeration.conf /etc/apache2/sites-available/enumeration.conf
sudo a2enmod rewrite env
sudo a2ensite enumeration
sudo a2dissite 000-default
sudo apache2ctl configtest
sudo systemctl restart apache2
```

Open `http://YOUR_SERVER_IP/`; moderator login is at `/admin.php`. The included virtual host serves **only `/var/www/html/public`**. The `.htaccess` file routes board URLs to the forum and lets Apache serve the decoy files and directories normally. If using another checkout path, edit both paths in `deploy/apache/enumeration.conf` and the path in `deploy/apache/enumeration-lab.conf`.

Set upload limits for mod_php, then restart Apache:

```bash
PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
printf 'upload_max_filesize=4M\npost_max_size=6M\n' | sudo tee "/etc/php/$PHP_VERSION/apache2/conf.d/99-enumeration.ini"
sudo systemctl restart apache2
curl -I http://127.0.0.1/
```

Diagnostics:

```bash
sudo journalctl -u apache2 -u mysql --since '10 minutes ago'
sudo tail -n 50 /var/log/apache2/error.log
sudo ss -lntp
```

`could not find driver` means `php-mysql` is missing for the PHP version Apache runs. `Access denied` means the configured MySQL username, password, or account host does not match. Missing-table errors mean `bin/init-db.php` has not run. Ensure `www-data` can write `data/` and read `config.php`.

## Existing SQLite installations

For a fresh server with no old data, skip this section. For an existing installation, stop the old forum before taking a backup and importing. Do not seed MySQL first.

```bash
sudo systemctl stop apache2
sudo cp -a /var/www/html/data /var/backups/enumeration-before-mysql
sudo apt install -y php-sqlite3
cd /var/www/html
sudo -u www-data php bin/migrate-sqlite.php /var/www/html/data/forum.sqlite
sudo systemctl start apache2
```

Run the importer while the database account still has `CREATE` and `REFERENCES` privileges. It requires an empty MySQL forum apart from the initialized boards. It copies IDs, accounts, password hashes, threads, timestamps, posts, attachment references, and quoted replies. Older posts without browser IDs receive legacy IDs. Rate-limit counters reset. If a row cannot be imported, imported rows roll back; the SQLite source stays unchanged. Re-running against a populated target is rejected.

Keep the same `data/` directory: it holds uploaded images and `admin.hash`. For migration to another machine, copy the stopped server's whole `data/` directory and give `www-data` ownership (`sudo chown -R www-data:www-data /var/www/html/data`). The SQLite file is a backup after migration and is no longer used by the app. `pdo_sqlite` is required only by the importer.

## 4. Add services on common ports

This optional selection gives scanners a mixture of common and less common services. It is intended for a fresh, private lab VM. Replace `192.168.56.10` below with the VM's actual private IPv4 address (`ip -br address`); the address must exist before starting the services.

| Service | Package | TCP port | Purpose |
| --- | --- | --- | --- |
| OpenSSH | `openssh-server` | 22 | Remote administration |
| vsftpd | `vsftpd` | 21 | Read-only anonymous FTP decoy |
| rsync daemon | `rsync` | 873 | Read-only sample file share |
| Eclipse Mosquitto | `mosquitto` | 1883 | MQTT broker |
| Memcached | `memcached` | 11211 | Disposable cache; UDP disabled |
| Apache2 | already installed | 80 | Forum and decoys |
| MySQL | already installed | 3306, loopback only | Forum database |

```bash
sudo add-apt-repository -y universe
sudo apt update
sudo apt install -y openssh-server vsftpd rsync mosquitto memcached
LAB_IP=192.168.56.10

sudo install -d -o root -g root -m 755 /srv/enumeration-share
printf 'Enumeration lab sample file. No real backups are stored here.\n' | sudo tee /srv/enumeration-share/readme.txt

sudo tee /etc/vsftpd.conf >/dev/null <<EOF_FTP
listen=YES
listen_ipv6=NO
listen_address=$LAB_IP
anonymous_enable=YES
no_anon_password=YES
local_enable=NO
write_enable=NO
anon_root=/srv/enumeration-share
anon_world_readable_only=YES
secure_chroot_dir=/var/run/vsftpd/empty
pam_service_name=vsftpd
xferlog_enable=YES
pasv_min_port=40000
pasv_max_port=40010
EOF_FTP

sudo tee /etc/rsyncd.conf >/dev/null <<EOF_RSYNC
address = $LAB_IP
port = 873
uid = nobody
gid = nogroup
use chroot = yes
read only = yes
[archive]
    path = /srv/enumeration-share
    comment = Archived public lab documents
    list = yes
EOF_RSYNC

sudo tee /etc/mosquitto/conf.d/enumeration.conf >/dev/null <<EOF_MQTT
listener 1883 $LAB_IP
allow_anonymous true
EOF_MQTT

sudo tee /etc/memcached.conf >/dev/null <<EOF_CACHE
-d
logfile /var/log/memcached.log
-m 32
-p 11211
-u memcache
-l $LAB_IP
-U 0
EOF_CACHE

sudo systemctl enable ssh vsftpd rsync mosquitto memcached
sudo systemctl restart ssh vsftpd rsync mosquitto memcached
sudo ss -lntup
```

The sample FTP and rsync shares are read-only. MQTT accepts anonymous lab clients; Memcached has no authentication and must stay on the private lab network. Confirm the displayed listeners bind to your chosen address. See the [Ubuntu vsftpd settings](https://manpages.ubuntu.com/manpages/noble/man5/vsftpd.conf.5.html), [rsync manual](https://manpages.ubuntu.com/manpages/noble/man1/rsync.1.html), and [Mosquitto authentication guide](https://www.mosquitto.org/documentation/authentication-methods/).

If UFW is already enabled, allow only the lab subnet (replace the example subnet). Keep your existing SSH management rule:

```bash
sudo ufw allow from 192.168.56.0/24 to any port 21,22,80,873,1883,11211 proto tcp
sudo ufw allow from 192.168.56.0/24 to any port 40000:40010 proto tcp
```

The high port range is for FTP data transfers. MySQL remains private to the server. Apply equivalent restrictions to any cloud firewall. To stop the optional services later:

```bash
sudo systemctl disable --now vsftpd rsync mosquitto memcached
```

## 5. Discoverable folders and files (honeypot decoys)

The repository already includes these static paths; copying `public/` deploys them:

- `/admin/`, `/administrator/`, `/old/`, `/staging/`, `/test/`, `/private/`, `/server-status/`
- `/backup/` and `/backup/forum.sql`
- `/backups/` and `/backups/manifest.txt`
- `/dev/` and `/dev/notes.txt`
- `/config/` and `/config/database.ini.bak`
- `/logs/` and `/logs/maintenance.txt`
- `/uploads/` and `/robots.txt`

Directory pages have `index.html` files, so directory listing can remain disabled. These are passive decoys, not credential collectors or executable backdoors. The apparent database settings and dump are fake. Real uploads and configuration remain outside the document root. The decoy `/admin/` is separate from the real `/admin.php` moderator page. Both Apache and the PHP development router serve the decoys.

Point DirBuster at `http://YOUR_LAB_SERVER_IP/`, use a common directory wordlist, and include `html,txt,sql,bak` extensions. Check responses and Apache's access log:

```bash
curl -i http://127.0.0.1/backup/
curl -i http://127.0.0.1/config/database.ini.bak
sudo tail -f /var/log/apache2/access.log
```

Unknown paths return 404. Access logging records probes; there is no automatic alerting component.

## Optional SQL injection challenge

Normal search uses prepared statements. To intentionally enable interpolation of the `q` parameter on this lab instance:

```bash
cd /var/www/html
sudo install -m 644 deploy/apache/enumeration-lab.conf /etc/apache2/conf-available/enumeration-lab.conf
sudo a2enconf enumeration-lab
sudo apache2ctl configtest && sudo systemctl reload apache2
```

For your own lab server, with SQLMap installed separately and the demo threads seeded:

```bash
sqlmap -u 'http://YOUR_LAB_SERVER_IP/search.php?board=gen&q=Welcome' -p q --dbms=MySQL --technique=BU --batch
```

Lab search accepts up to 4,096 characters and retains MySQL comment whitespace. Normal search is capped at 120 characters. Disable the challenge with:

```bash
sudo a2disconf enumeration-lab
sudo apache2ctl configtest && sudo systemctl reload apache2
```

## Configuration and checks

| Variable | Default / purpose |
| --- | --- |
| `FORUM_DB_DSN` | `mysql:host=127.0.0.1;port=3306;dbname=enumeration;charset=utf8mb4` |
| `FORUM_DB_USER` | `forum` |
| `FORUM_DB_PASSWORD` | Database password from `config.php` or the environment |
| `FORUM_CONFIG` | Optional alternate PHP config file; default is repository `config.php` |
| `FORUM_DATA_DIR` | Private file storage; default is repository `data/` |
| `FORUM_ADMIN_HASH` | Optional moderator password hash, overriding `data/admin.hash` |
| `FORUM_LAB_MODE` | Exactly `1` enables vulnerable search; default is off |

Only the database settings are read from `config.php`; the other overrides are environment variables. Environment database settings take precedence. Changing `FORUM_DATA_DIR` does **not** select another database; change the DSN as well when isolating instances.

For local development, after database initialization:

```bash
php -d upload_max_filesize=4M -d post_max_size=6M -S 127.0.0.1:8080 -t public public/router.php
```

Run syntax and HTTP integration checks from the repository root. Tests create and drop uniquely named `enumeration_test_*` databases, using a separate test account; they never reuse the configured application database:

```bash
sudo apt install -y python3 php-sqlite3
sudo mysql
```

```sql
CREATE USER 'forum_test'@'127.0.0.1' IDENTIFIED BY 'REPLACE_WITH_A_TEST_PASSWORD';
GRANT ALL PRIVILEGES ON `enumeration\_test\_%`.* TO 'forum_test'@'127.0.0.1';
EXIT;
```

```bash
export FORUM_TEST_USER=forum_test
read -rsp 'Test database password: ' FORUM_TEST_PASSWORD; echo
export FORUM_TEST_PASSWORD
# Optional override for a different MySQL host/port; do not include dbname.
export FORUM_TEST_DSN='mysql:host=127.0.0.1;port=3306;charset=utf8mb4'
find app public bin -name '*.php' -exec php -l {} \;
python3 tests/integration.py
python3 tests/accounts.py
unset FORUM_TEST_PASSWORD
```

The suites cover posting, account login, avatars, moderation, rate limits, search in normal and lab modes, decoy routing, and importing a legacy SQLite fixture. Back up MySQL with `mysqldump --single-transaction --no-tablespaces -h 127.0.0.1 -u forum -p enumeration` together with `data/`; pause forum writes while taking both backups if you need matching database and attachment snapshots.
