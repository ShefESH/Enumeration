# Enumeration lab installer

The existing application and its manual setup guide are now in `Enumeration/`.
Git metadata stays at the repository root. `install.sh` installs a fresh lab device.

## Run

Use **Ubuntu Server 24.04 LTS** on the bare-metal device, booted with systemd and
working Internet access. Multiple Ethernet ports and Wi-Fi are supported; select
the physical Ethernet port to use for the lab. Networking must be managed through
Netplan. Run from the local console because changing the selected port can disconnect
SSH. This is a fresh-install script, not an upgrade or repair script.

```bash
ip -br link
sudo bash install.sh enp2s0
# With exactly one physical Ethernet port, its name can be omitted:
sudo bash install.sh
```

The installer prompts twice for your standard **forum database password**, with
input hidden. It uses that exact password for MySQL's `forum` account and writes
it to `/var/www/html/config.php` as `FORUM_DB_PASSWORD`, safely preserving quotes,
backslashes, dollar signs and spaces. The file is readable by root and `www-data`
only and is outside the public web directory. MySQL root authentication is unchanged.
Before continuing, the installer checks the password through the application's
configuration loader and opens a real database connection with it.

The script:

1. Installs Apache2, PHP 8.3, MySQL, Samba (SMB), vsftpd (FTP), OpenSSH and rsync.
2. Copies `Enumeration/` into `/var/www/html`, including `.htaccess`. Local
   runtime data and `config.php` are excluded; it uses the database password you enter,
   initializes the schema and flags, and seeds the demo threads.
3. Installs `Enumeration/deploy/apache/apache.conf` as
   `/etc/apache2/sites-available/enumeration.conf`, enables it, and restarts Apache.
   Only `/var/www/html/public` is served. SQL injection lab mode remains optional;
   see `Enumeration/README.md` to enable it.
4. Creates a read-only guest share at `/srv/enumeration-share` for SMB, FTP and
   rsync. SSH uses the device's existing accounts.
5. Starts `secret-service.service`, with description and HTTP banner
   `SHEFESH{secret_service}`, on a randomly chosen free TCP port from
   **8000, 8080, 8888, 9000, 9090**. The port is saved in
   `/etc/default/secret-service` and survives reboot. Scanners may label the
   protocol HTTP; the custom name appears in its `Server` header and response body.
6. Backs up and consolidates Netplan YAML, changing only the selected Ethernet
   definition and preserving the other interface definitions and renderer. It applies:
   **192.168.50.2/24**, netmask **255.255.255.0**, gateway **192.168.50.1**.
   DNS defaults to **192.168.50.1**; edit `DNS_SERVER` if needed. Existing network
   YAML and service configurations are backed up under `/var/backups/enumeration-*`.
   Cloud-init network regeneration is disabled when cloud-init is present.
   The lab default route receives a higher metric than existing default routes,
   so a separate management/Internet connection retains priority. The selected
   port's IPv6 settings and non-default routes are retained. Ambiguous shared
   Netplan match rules and ports belonging to a bridge/bond require a dedicated
   Ethernet definition first; the script stops rather than altering those networks.

The anonymous shares are intended for the private lab network. If UFW is already
active, the installer adds lab-subnet rules for TCP 21, 22, 80, 139, 445, 873,
the chosen secret port, FTP passive ports 40000–40010, and UDP 137–138. It does not
enable UFW or change any external firewall.

## Fixed versions

The OS target is Ubuntu **24.04 LTS**, PHP is **8.3**, and package selection uses
the fixed Ubuntu archive snapshot **20261101T000000Z** (1 November 2026).
The installer isolates its apt sources, resolves the versions from that snapshot,
and installs explicit `package=version` arguments. It writes those exact versions
to `/var/lib/enumeration-install/packages.lock` and holds the requested packages.
It refuses automatic downgrades if a newer package is already installed.

The complete installed package inventory is saved as
`/var/lib/enumeration-install/installed-packages.txt`. Change `APT_SNAPSHOT` to
select a different archive date before installation. To maintain the device later,
explicitly unhold the packages you intend to upgrade using `apt-mark unhold`.
Other system packages retain the device's normal update policy.

See the [Ubuntu Snapshot Service](https://snapshot.ubuntu.com/) and
[Netplan configuration reference](https://netplan.readthedocs.io/en/stable/netplan-yaml/)
for the underlying package and network configuration formats.
