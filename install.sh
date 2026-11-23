#!/usr/bin/env bash
# Ubuntu Server 24.04 LTS on a dedicated lab device. Run from its local console.
# Usage: sudo bash install.sh [ethernet-interface]
set -Eeuo pipefail
set +x # Never trace password input or the pipe that passes it to PHP.
trap 'printf "Installation stopped at line %s. Review the error above.\n" "$LINENO" >&2' ERR

# Fixed OS, PHP branch, and package archive date (not floating "latest").
readonly UBUNTU_VERSION='24.04'
readonly APT_SNAPSHOT='20261101T000000Z'
readonly LAB_IP='192.168.50.2'
readonly LAB_CIDR='192.168.50.2/24' # netmask 255.255.255.0
readonly GATEWAY='192.168.50.1'
readonly DNS_SERVER='192.168.50.1' # Change if the gateway does not provide DNS.
readonly WEB_ROOT='/var/www/html'
readonly STATE='/var/lib/enumeration-install'
SOURCE_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)/Enumeration"

die() { printf '%s\n' "$*" >&2; exit 1; }
[[ $EUID -eq 0 ]] || die 'Run with sudo on the target device.'
[[ $# -le 1 ]] || die 'Usage: sudo bash install.sh [ethernet-interface]'
[[ -f /etc/os-release ]] || die 'Ubuntu Server 24.04 is required.'
# shellcheck disable=SC1091
source /etc/os-release
[[ $ID == ubuntu && $VERSION_ID == "$UBUNTU_VERSION" ]] || die 'Only Ubuntu Server 24.04 LTS is supported.'
[[ -d /run/systemd/system ]] || die 'A booted system with systemd is required.'
[[ -f "$SOURCE_DIR/deploy/apache/apache.conf" ]] || die 'The adjacent Enumeration/ folder is missing.'
[[ ! -e "$STATE/installed" && ! -e "$WEB_ROOT/app/bootstrap.php" ]] || die 'This is a fresh-install script; the forum is already installed.'

# Auto-select only when there is one physical wired NIC; otherwise require its name.
NICS=()
for device in /sys/class/net/*; do
    [[ -e $device/device && ! -d $device/wireless && $(cat "$device/type") == 1 ]] || continue
    NICS+=("${device##*/}")
done
if [[ $# -eq 1 ]]; then
    INTERFACE=$1
else
    [[ ${#NICS[@]} -eq 1 ]] || die "Specify the Ethernet interface: sudo bash install.sh <interface>. Available: ${NICS[*]:-none}"
    INTERFACE=${NICS[0]}
fi
[[ $INTERFACE =~ ^[a-zA-Z0-9_.-]+$ && -e /sys/class/net/$INTERFACE/device ]] || die 'Select an existing physical Ethernet interface.'
[[ ! -d /sys/class/net/$INTERFACE/wireless && $(cat "/sys/class/net/$INTERFACE/type") == 1 ]] || die 'The selected interface is not Ethernet.'
# Other Netplan sources can override /etc/netplan; refuse that layout.
for directory in /run/netplan /lib/netplan; do
    [[ ! -d $directory ]] || [[ -z $(find "$directory" -maxdepth 1 -name '*.yaml' -print -quit) ]] || die "Remove conflicting Netplan configuration in $directory first."
done

# Accept the normal forum database password without showing it or putting it in argv.
IFS= read -r -s -p 'Database password for forum: ' DB_PASSWORD
printf '\n'
IFS= read -r -s -p 'Confirm database password: ' DB_PASSWORD_CONFIRM
printf '\n'
[[ -n $DB_PASSWORD && $DB_PASSWORD == "$DB_PASSWORD_CONFIRM" ]] || die 'Passwords must be non-empty and match.'
unset DB_PASSWORD_CONFIRM

export DEBIAN_FRONTEND=noninteractive
install -d -m 755 "$STATE" "$STATE/lists/partial"
# Isolated apt sources: third-party repositories cannot change the selected versions.
cat > "$STATE/ubuntu.sources" <<EOF
Types: deb
URIs: https://snapshot.ubuntu.com/ubuntu/$APT_SNAPSHOT/
Suites: noble noble-updates noble-security
Components: main universe restricted multiverse
Signed-By: /usr/share/keyrings/ubuntu-archive-keyring.gpg
EOF
cat > "$STATE/snapshot.preferences" <<'EOF'
Package: *
Pin: origin "snapshot.ubuntu.com"
Pin-Priority: 1001
EOF
APT_OPTIONS=(-o "Dir::Etc::sourcelist=$STATE/ubuntu.sources"
    -o 'Dir::Etc::sourceparts=-' -o "Dir::State::lists=$STATE/lists"
    -o "Dir::Etc::preferences=$STATE/snapshot.preferences" -o 'Dir::Etc::preferencesparts=-')
PACKAGES=(apache2 mysql-server php8.3-cli libapache2-mod-php8.3 php8.3-mysql
    php8.3-gd php8.3-mbstring samba vsftpd openssh-server rsync python3 python3-yaml
    netplan.io iproute2 curl openssl)
apt-get "${APT_OPTIONS[@]}" update
PINNED_PACKAGES=()
for package in "${PACKAGES[@]}"; do
    version=$(LC_ALL=C apt-cache "${APT_OPTIONS[@]}" policy "$package" | awk '/Candidate:/ {print $2}')
    [[ -n $version && $version != '(none)' ]] || die "No snapshot version available for $package."
    PINNED_PACKAGES+=("$package=$version")
done
printf '%s\n' "${PINNED_PACKAGES[@]}" | tee "$STATE/packages.lock"
# Fail instead of silently downgrading a newer preinstalled package.
apt-get "${APT_OPTIONS[@]}" install -y "${PINNED_PACKAGES[@]}"
# Keep the requested service versions fixed until explicitly unheld by the operator.
apt-mark hold "${PACKAGES[@]}"
dpkg-query -W -f='${binary:Package}=${Version}\n' > "$STATE/installed-packages.txt"

# Back up any stock configuration before replacing it.
BACKUP="/var/backups/enumeration-$(date -u +%Y%m%dT%H%M%SZ)"
install -d -m 700 "$BACKUP"
for file in /etc/samba/smb.conf /etc/vsftpd.conf /etc/rsyncd.conf; do
    [[ ! -f $file ]] || cp -a "$file" "$BACKUP/"
done

# Prepare a merged configuration, changing only the selected Ethernet definition.
# Validate it in a staging directory before replacing any live configuration.
NETWORK_STAGE=$(mktemp -d "$STATE/netplan.XXXXXX")
install -d -m 700 "$NETWORK_STAGE/etc/netplan"
python3 "$SOURCE_DIR/deploy/lab/configure-network.py" "$INTERFACE" "$LAB_CIDR" "$GATEWAY" "$DNS_SERVER" \
    > "$NETWORK_STAGE/etc/netplan/99-enumeration.yaml"
chmod 600 "$NETWORK_STAGE/etc/netplan/99-enumeration.yaml"
netplan generate --root-dir "$NETWORK_STAGE"

# Copy application files, including .htaccess. Keep local runtime data out of deployment.
install -d -m 755 "$WEB_ROOT"
rsync -a --exclude='/data/*' --exclude='/config.php' --exclude='__pycache__/' \
    --exclude='.DS_Store' "$SOURCE_DIR/" "$WEB_ROOT/"
chown -R root:root "$WEB_ROOT"
install -d -o www-data -g www-data -m 700 "$WEB_ROOT/data"

# The PHP forum also needs its database to be usable after Apache starts.
systemctl enable --now mysql
install -o root -g www-data -m 640 /dev/null "$WEB_ROOT/config.php"
printf '%s' "$DB_PASSWORD" | php "$WEB_ROOT/bin/install-db.php"
unset DB_PASSWORD
# All CLI setup uses the same config.php that Apache will read.
unset FORUM_DB_DSN FORUM_DB_USER FORUM_DB_PASSWORD FORUM_CONFIG
runuser -u www-data -- php "$WEB_ROOT/bin/init-db.php"
if [[ $(mysql -N -B enumeration -e 'SELECT COUNT(*) FROM threads') == 0 ]]; then
    runuser -u www-data -- php "$WEB_ROOT/bin/seed.php"
fi
mysql -e "REVOKE CREATE, REFERENCES ON enumeration.* FROM 'forum'@'127.0.0.1';"

# Install the codebase's Apache virtual host in the normal sites directory.
install -m 644 "$SOURCE_DIR/deploy/apache/apache.conf" /etc/apache2/sites-available/enumeration.conf
a2enmod rewrite env php8.3
a2ensite enumeration
a2dissite 000-default
printf 'upload_max_filesize=4M\npost_max_size=6M\n' > /etc/php/8.3/apache2/conf.d/99-enumeration.ini
apache2ctl configtest
systemctl enable apache2
systemctl restart apache2
curl --fail --silent --output /dev/null http://127.0.0.1/

# SMB, FTP and rsync share the same read-only sample directory. SSH uses existing system accounts.
install -d -m 755 /srv/enumeration-share
printf 'Enumeration lab archive. No live backups are stored here.\n' > /srv/enumeration-share/readme.txt
chmod 644 /srv/enumeration-share/readme.txt
install -m 644 "$SOURCE_DIR/deploy/lab/smb.conf" /etc/samba/smb.conf
install -m 644 "$SOURCE_DIR/deploy/lab/vsftpd.conf" /etc/vsftpd.conf
install -m 644 "$SOURCE_DIR/deploy/lab/rsyncd.conf" /etc/rsyncd.conf
testparm -s /etc/samba/smb.conf >/dev/null

# Pick a free common HTTP port once and persist it for subsequent boots.
SECRET_PORT=$(python3 - <<'PY'
import random, socket
ports = [8000, 8080, 8888, 9000, 9090]
random.SystemRandom().shuffle(ports)
for port in ports:
    try:
        with socket.socket() as sock:
            sock.bind(('0.0.0.0', port))
        print(port)
        break
    except OSError:
        continue
else:
    raise SystemExit('All candidate secret-service ports are occupied.')
PY
)
printf 'SECRET_PORT=%s\n' "$SECRET_PORT" > /etc/default/secret-service
install -d -m 755 /usr/local/lib/enumeration
install -m 644 "$SOURCE_DIR/deploy/lab/secret-service.py" /usr/local/lib/enumeration/secret-service.py
install -m 644 "$SOURCE_DIR/deploy/lab/secret-service.service" /etc/systemd/system/secret-service.service
systemctl daemon-reload
systemctl enable smbd nmbd vsftpd ssh rsync secret-service
systemctl restart smbd nmbd vsftpd ssh rsync secret-service
for service in apache2 mysql smbd nmbd vsftpd ssh rsync secret-service; do
    systemctl is-active --quiet "$service"
done
curl --fail --silent --retry 5 --retry-connrefused "http://127.0.0.1:$SECRET_PORT/"

# If UFW is already active, admit these services from the lab subnet only.
if command -v ufw >/dev/null && LC_ALL=C ufw status | grep -q '^Status: active'; then
    ufw allow from 192.168.50.0/24 to any port "21,22,80,139,445,873,$SECRET_PORT" proto tcp
    ufw allow from 192.168.50.0/24 to any port 137,138 proto udp
    ufw allow from 192.168.50.0/24 to any port 40000:40010 proto tcp
fi

# Apply networking LAST, after downloads and service setup. This may disconnect SSH.
# Preserve all other interfaces and renderer settings in the merged configuration.
install -d -m 700 "$BACKUP/netplan"
install -d -m 755 /etc/netplan
shopt -s nullglob
for file in /etc/netplan/*.yaml; do mv "$file" "$BACKUP/netplan/"; done
install -m 600 "$NETWORK_STAGE/etc/netplan/99-enumeration.yaml" /etc/netplan/99-enumeration.yaml
if ! netplan generate; then
    rm /etc/netplan/99-enumeration.yaml
    for file in "$BACKUP/netplan/"*.yaml; do cp -a "$file" /etc/netplan/; done
    die "Netplan validation failed; original files restored from $BACKUP/netplan."
fi
if [[ -d /etc/cloud/cloud.cfg.d ]]; then
    [[ ! -f /etc/cloud/cloud.cfg.d/99-disable-network-config.cfg ]] || cp -a /etc/cloud/cloud.cfg.d/99-disable-network-config.cfg "$BACKUP/"
    printf 'network: {config: disabled}\n' > /etc/cloud/cloud.cfg.d/99-disable-network-config.cfg
fi
printf '\nApplying %s on %s, gateway %s. Reconnect at the new address.\n' "$LAB_CIDR" "$INTERFACE" "$GATEWAY"
printf 'Forum: http://%s/\nSecret banner: http://%s:%s/\nBackups: %s\nPackage versions: %s/packages.lock\n' "$LAB_IP" "$LAB_IP" "$SECRET_PORT" "$BACKUP" "$STATE"
netplan apply
ip -4 -o address show dev "$INTERFACE" | grep -Fq " $LAB_CIDR "
ip -4 route show default | grep -Fq "via $GATEWAY dev $INTERFACE"
touch "$STATE/installed"
