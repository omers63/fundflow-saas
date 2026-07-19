# Mailpit on Hostinger VPS (FundFlow)

Guide for testing outbound email with Mailpit on this VPS (setup session 2026-07-18). Use instead of a real SMTP provider while developing.

> **Secrets:** UI username/password live only on the server at `/root/mailpit-ui-credentials.txt` (mode 600). Do not commit passwords or `.env` mail secrets into git.

## Current state

| Item | Value |
|------|--------|
| Binary | `/usr/local/bin/mailpit` |
| systemd unit | `mailpit.service` (enabled, active) |
| SMTP | `127.0.0.1:1025` (localhost only) |
| Web UI | `127.0.0.1:8025` (localhost only) |
| UI auth file | `/etc/mailpit/ui-auth` (mode 600) |
| UI credentials | `/root/mailpit-ui-credentials.txt` (mode 600) |
| Message DB | `/var/lib/mailpit/mailpit.db` |
| Max messages | 1000 |

SMTP and the UI are **not** exposed on the public interface. Access the UI only via SSH tunnel.

## Laravel `.env`

```env
MAIL_MAILER=smtp
MAIL_SCHEME=null
MAIL_HOST=127.0.0.1
MAIL_PORT=1025
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_ENCRYPTION=null
MAIL_FROM_ADDRESS="admin@fundflow.sa"
MAIL_FROM_NAME="${APP_NAME}"
```

After changing mail settings:

```bash
cd /var/www/fundflow-saas
php artisan config:clear
```

> Mailpit SMTP on localhost does not require auth. If `.env` has `MAIL_USERNAME` / `MAIL_PASSWORD` set, they are unused by Mailpit SMTP; UI login credentials remain in `/root/mailpit-ui-credentials.txt`.

## systemd unit

Path: `/etc/systemd/system/mailpit.service`

```ini
[Unit]
Description=Mailpit SMTP Service
After=network.target

[Service]
ExecStart=/usr/local/bin/mailpit \
  --smtp 127.0.0.1:1025 \
  --listen 127.0.0.1:8025 \
  --ui-auth-file /etc/mailpit/ui-auth \
  --max 1000 \
  --database /var/lib/mailpit/mailpit.db
Restart=always
RestartSec=3
User=root

[Install]
WantedBy=multi-user.target
```

Reload / restart:

```bash
sudo systemctl daemon-reload
sudo systemctl restart mailpit
sudo systemctl status mailpit --no-pager
```

## Open the Web UI (SSH tunnel)

From your laptop:

```bash
ssh -L 8025:127.0.0.1:8025 root@YOUR_VPS_IP
```

Then open: http://127.0.0.1:8025

| Field | Value |
|-------|--------|
| Username | `admin` |
| Password | see `/root/mailpit-ui-credentials.txt` on the VPS |

## Send a test email

```bash
cd /var/www/fundflow-saas
php artisan tinker --execute 'Illuminate\Support\Facades\Mail::raw("Mailpit test", fn ($m) => $m->to("you@example.com")->subject("Test"));'
```

Confirm via API (replace `PASSWORD` from the credentials file):

```bash
curl -s -u 'admin:PASSWORD' http://127.0.0.1:8025/api/v1/messages | python3 -m json.tool | head
```

## Regenerate UI password

```bash
PASS=$(openssl rand -base64 18 | tr -d '/+=' | head -c 20)
export PASS
HASH=$(python3 -c 'import bcrypt,os; print(bcrypt.hashpw(os.environ["PASS"].encode(), bcrypt.gensalt(rounds=10)).decode())')

printf 'admin:%s\n' "$HASH" | sudo tee /etc/mailpit/ui-auth >/dev/null
sudo chmod 600 /etc/mailpit/ui-auth

umask 077
printf 'Mailpit UI credentials\nURL (via SSH tunnel): http://127.0.0.1:8025\nUsername: admin\nPassword: %s\nGenerated: %s\n' \
  "$PASS" "$(date -Is)" > /root/mailpit-ui-credentials.txt
chmod 600 /root/mailpit-ui-credentials.txt

sudo systemctl restart mailpit
```

## Useful commands

```bash
systemctl status mailpit
journalctl -u mailpit -f
ss -tlnp | grep -E '1025|8025'
```

## Hardening notes

1. Bind SMTP and UI to `127.0.0.1` so they are not reachable from the internet.
2. Protect the UI with `--ui-auth-file` (bcrypt basic auth).
3. Prefer SSH port forwarding over opening `8025` in the firewall.
4. Do not commit UI passwords or `.env` mail secrets into git.

## Install Mailpit (if rebuilding a fresh VPS)

```bash
# Official binary install — check https://mailpit.axllent.org for current instructions
sudo bash < <(curl -sL https://raw.githubusercontent.com/axllent/mailpit/develop/install.sh)

# Then install the systemd unit above, create /etc/mailpit/ui-auth,
# and point Laravel MAIL_* at 127.0.0.1:1025.
```

Alternative: run via Docker if preferred on that host; keep ports on localhost only.

## Related server file

A root-only copy of this guide also exists at `/root/mailpit-setup.md`.
