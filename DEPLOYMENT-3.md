# Deploying the Tenancy Starter Kit to Hostinger KVM 2 (Ubuntu, no panel)

End-to-end deployment guide for this multi-tenant Laravel app
(Laravel 12 + Filament 5 + Livewire 4 + `stancl/tenancy` v3).

**Target environment**

- Hostinger VPS — **KVM 2**: 2 vCPU, 8 GB RAM, 100 GB NVMe
- **Ubuntu 24.04 LTS**, no control panel (pure SSH)
- Wildcard subdomain tenancy on `*.yourdomain.com`

Two non-negotiables for this project:

1. **Wildcard DNS + wildcard SSL** — tenants live on `*.yourdomain.com`.
2. **MySQL/MariaDB for production** — SQLite works locally but doesn't fit per-tenant DB provisioning at scale.

> Throughout this doc, replace `yourdomain.com` with your real domain
> and `deploy` with whatever non-root username you prefer.

---

## 0. First login & basic hardening

Hostinger emails you the root password and IP. SSH in:

```bash
ssh root@YOUR_VPS_IP
```

### 0.1 Create a non-root deploy user

```bash
adduser deploy                    # set a strong password
usermod -aG sudo deploy
mkdir -p /home/deploy/.ssh
cp ~/.ssh/authorized_keys /home/deploy/.ssh/ 2>/dev/null || true
# If you didn't paste a key during VPS setup, run on your local machine:
#   ssh-copy-id deploy@YOUR_VPS_IP
chown -R deploy:deploy /home/deploy/.ssh
chmod 700 /home/deploy/.ssh
chmod 600 /home/deploy/.ssh/authorized_keys 2>/dev/null || true
```

### 0.2 Lock down SSH

Edit `/etc/ssh/sshd_config`:

```conf
PermitRootLogin no
PasswordAuthentication no
PubkeyAuthentication yes
```

```bash
systemctl restart ssh
```

From now on log in as `deploy`:

```bash
ssh deploy@YOUR_VPS_IP
```

### 0.3 Firewall + fail2ban

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y ufw fail2ban
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow OpenSSH
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
sudo systemctl enable --now fail2ban
```

### 0.4 Swap (safety net for 8 GB box)

```bash
sudo fallocate -l 4G /swapfile
sudo chmod 600 /swapfile
sudo mkswap /swapfile
sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
echo 'vm.swappiness=10' | sudo tee -a /etc/sysctl.conf
sudo sysctl -p
```

### 0.5 Time sync & hostname (optional but tidy)

```bash
sudo timedatectl set-timezone UTC
sudo hostnamectl set-hostname tenancy-prod
```

---

## 1. Install the LEMP stack

PHP 8.4 isn't in the default Ubuntu 24.04 repo — use Ondrej's PPA.

```bash
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update

sudo apt install -y \
    nginx mariadb-server redis-server supervisor git unzip curl \
    php8.4-fpm php8.4-cli php8.4-mysql php8.4-redis php8.4-mbstring \
    php8.4-xml php8.4-bcmath php8.4-curl php8.4-zip php8.4-gd \
    php8.4-intl php8.4-sqlite3 php8.4-fileinfo php8.4-pcov

curl -sS https://getcomposer.org/installer | sudo php -- \
    --install-dir=/usr/local/bin --filename=composer

curl -fsSL https://deb.nodesource.com/setup_20.x | sudo bash -
sudo apt install -y nodejs
```

Verify:

```bash
php -v        # PHP 8.4.x
composer -V
node -v       # v20.x
nginx -v
mariadb --version
redis-cli ping  # PONG
```

---

## 2. Resource tuning for KVM 2 (2 vCPU / 8 GB)

Rough RAM budget on an 8 GB box (single-app server):

| Service               | Reserved RAM |
| --------------------- | -----------: |
| OS + sshd + journald  |      ~500 MB |
| MariaDB (InnoDB)      |      ~2.0 GB |
| Redis                 |      ~512 MB |
| PHP-FPM (web + admin) |      ~3.0 GB |
| Queue workers         |      ~512 MB |
| Nginx + headroom      |    remainder |

### 2.1 MariaDB

Edit `/etc/mysql/mariadb.conf.d/50-server.cnf`, inside `[mysqld]`:

```ini
innodb_buffer_pool_size = 2G
innodb_log_file_size    = 256M
innodb_flush_method     = O_DIRECT
innodb_flush_log_at_trx_commit = 2
max_connections         = 150
max_allowed_packet      = 64M
table_open_cache        = 2000
```

```bash
sudo systemctl restart mariadb
sudo mysql_secure_installation   # set root pw, remove anonymous, disallow remote root, drop test db
```

Create the central DB + tenancy-capable user:

```bash
sudo mariadb
```

```sql
CREATE DATABASE tenancy_central CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER 'tenancy'@'localhost' IDENTIFIED BY 'CHANGE_ME_STRONG';
GRANT ALL PRIVILEGES ON *.* TO 'tenancy'@'localhost' WITH GRANT OPTION;
FLUSH PRIVILEGES;
EXIT;
```

> The grant is global because `MySQLDatabaseManager` issues `CREATE DATABASE`/`DROP DATABASE` per tenant.
> If you need tighter isolation, swap in `PermissionControlledMySQLDatabaseManager` (`config/tenancy.php` ~line 66).

### 2.2 Redis

Edit `/etc/redis/redis.conf`:

```conf
maxmemory 512mb
maxmemory-policy allkeys-lru
appendonly no
```

```bash
sudo systemctl restart redis-server
```

### 2.3 PHP-FPM pool (`/etc/php/8.4/fpm/pool.d/www.conf`)

Tuned for ~50 MB per worker, ~3 GB budget:

```ini
pm = dynamic
pm.max_children       = 40
pm.start_servers      = 6
pm.min_spare_servers  = 4
pm.max_spare_servers  = 12
pm.max_requests       = 500
request_terminate_timeout = 60s
```

Bump PHP memory in `/etc/php/8.4/fpm/php.ini` and `/etc/php/8.4/cli/php.ini`:

```ini
memory_limit       = 256M
upload_max_filesize = 64M
post_max_size      = 64M
max_execution_time = 60
```

Enable OPcache (`/etc/php/8.4/fpm/conf.d/10-opcache.ini`):

```ini
opcache.enable=1
opcache.enable_cli=0
opcache.memory_consumption=192
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0
opcache.save_comments=1
```

> `validate_timestamps=0` means OPcache **won't auto-pick up code changes**; you must run `php artisan opcache:clear` (or restart php-fpm) on every deploy. The redeploy script in §11 does this.

```bash
sudo systemctl restart php8.4-fpm
```

### 2.4 Nginx (`/etc/nginx/nginx.conf` top-level)

```nginx
worker_processes auto;
events { worker_connections 1024; }
http {
    client_max_body_size 64m;
    gzip on;
    gzip_types text/plain text/css application/json application/javascript text/xml application/xml;
}
```

---

## 3. DNS — wildcard tenancy

In Hostinger **hPanel → Domains → DNS Zone Editor** for `yourdomain.com`:

| Type | Name  | Value (TTL 3600) |
| ---- | ----- | ---------------- |
| A    | `@`   | YOUR_VPS_IP      |
| A    | `www` | YOUR_VPS_IP      |
| A    | `*`   | YOUR_VPS_IP      |

The wildcard `A` record lets `acme.yourdomain.com`, `samman.yourdomain.com`, etc. resolve to the VPS so `InitializeTenancyByDomain` can identify them.

Verify after ~5 min:

```bash
dig +short yourdomain.com
dig +short any.yourdomain.com
```

Both should print `YOUR_VPS_IP`.

---

## 4. SSL — wildcard certificate (DNS-01)

A regular HTTP-01 cert won't cover `*.yourdomain.com`. You need DNS-01.

### 4.1 Easiest path (recommended): move DNS to Cloudflare

If you can change nameservers from Hostinger to Cloudflare, renewals become fully automatic:

```bash
sudo apt install -y certbot python3-certbot-dns-cloudflare

sudo mkdir -p /etc/letsencrypt
cat <<'EOF' | sudo tee /etc/letsencrypt/cloudflare.ini >/dev/null
dns_cloudflare_api_token = YOUR_SCOPED_CLOUDFLARE_TOKEN
EOF
sudo chmod 600 /etc/letsencrypt/cloudflare.ini

sudo certbot certonly \
  --dns-cloudflare \
  --dns-cloudflare-credentials /etc/letsencrypt/cloudflare.ini \
  -d yourdomain.com -d '*.yourdomain.com' \
  --agree-tos -m you@yourdomain.com --non-interactive
```

Auto-renewal is already wired via the `certbot.timer` systemd unit:

```bash
systemctl list-timers | grep certbot
sudo certbot renew --dry-run
```

### 4.2 Hostinger-DNS-only path (manual)

If you keep Hostinger's nameservers:

```bash
sudo apt install -y certbot
sudo certbot certonly --manual --preferred-challenges=dns \
  -d yourdomain.com -d '*.yourdomain.com'
```

Certbot will print TXT records — paste them into Hostinger DNS Zone Editor, wait for propagation, then continue. Renew every ~80 days manually (Hostinger doesn't yet have an official public Certbot plugin).

---

## 5. Application setup

### 5.1 Deploy directory & code

```bash
sudo mkdir -p /var/www
sudo chown deploy:deploy /var/www
cd /var/www
git clone https://github.com/YOUR_ORG/tenancy-starterkit.git
cd tenancy-starterkit

composer install --no-dev --optimize-autoloader
npm ci && npm run build
```

### 5.2 `.env` for production

```bash
cp .env.example .env
nano .env
```

```env
APP_NAME="Tenancy Starter"
APP_ENV=production
APP_DEBUG=false
APP_KEY=                              # filled in by key:generate below
APP_URL=https://yourdomain.com
CENTRAL_DOMAIN=yourdomain.com

APP_LOCALE=en
APP_FALLBACK_LOCALE=en

LOG_CHANNEL=stack
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=tenancy_central
DB_USERNAME=tenancy
DB_PASSWORD=CHANGE_ME_STRONG

CACHE_STORE=redis
SESSION_DRIVER=redis
SESSION_LIFETIME=120
SESSION_DOMAIN=.yourdomain.com        # share session across central + tenant subdomains if needed
QUEUE_CONNECTION=redis

REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

FILESYSTEM_DISK=local                 # switch to s3 later if you outgrow local storage

MAIL_MAILER=smtp
MAIL_HOST=smtp.your-provider.com
MAIL_PORT=587
MAIL_USERNAME=your-smtp-user
MAIL_PASSWORD=your-smtp-pass
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="no-reply@yourdomain.com"
MAIL_FROM_NAME="${APP_NAME}"
```

```bash
php artisan key:generate
```

### 5.3 Permissions

```bash
sudo chown -R deploy:www-data /var/www/tenancy-starterkit
sudo find /var/www/tenancy-starterkit -type f -exec chmod 644 {} \;
sudo find /var/www/tenancy-starterkit -type d -exec chmod 755 {} \;
sudo chown -R www-data:www-data storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod 775 {} \;
sudo find storage bootstrap/cache -type f -exec chmod 664 {} \;
```

### 5.4 Migrate + seed

```bash
php artisan migrate --force                # central DB
php artisan tenants:migrate --force        # any tenants already in central DB
php artisan db:seed --force                # roles, plans, admin user (optional)
```

### 5.5 Cache for production

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
php artisan icons:cache
php artisan filament:cache-components
```

> Run these in §11 after every deploy too.

---

## 6. Nginx site config

`/etc/nginx/sites-available/tenancy-starterkit`:

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name yourdomain.com *.yourdomain.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name yourdomain.com *.yourdomain.com;

    root /var/www/tenancy-starterkit/public;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/yourdomain.com/privkey.pem;
    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_ciphers         HIGH:!aNULL:!MD5;
    ssl_prefer_server_ciphers on;
    ssl_session_cache   shared:SSL:10m;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    charset utf-8;
    client_max_body_size 64m;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 60;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    # Cache static assets aggressively (Vite outputs hashed filenames)
    location ~* \.(?:css|js|woff2?|ttf|svg|png|jpg|jpeg|gif|ico)$ {
        expires 1y;
        access_log off;
        add_header Cache-Control "public, immutable";
        try_files $uri =404;
    }
}
```

Enable + test + reload:

```bash
sudo ln -sf /etc/nginx/sites-available/tenancy-starterkit /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t
sudo systemctl reload nginx
```

---

## 7. Queue worker (Supervisor)

Tenancy enqueues `MigrateDatabase`, `SeedDatabase`, etc. on every `TenantCreated` — the queue **must** be running.

`/etc/supervisor/conf.d/tenancy-worker.conf`:

```ini
[program:tenancy-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/tenancy-starterkit/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600 --backoff=10
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/tenancy-starterkit/storage/logs/worker.log
stopwaitsecs=3600
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start tenancy-worker:*
sudo supervisorctl status
```

> `numprocs=2` matches your 2 vCPU. Bump to 3–4 once you have lots of tenants if jobs back up.

---

## 8. Scheduler (cron)

```bash
sudo crontab -u www-data -e
```

Add:

```cron
* * * * * cd /var/www/tenancy-starterkit && php artisan schedule:run >> /dev/null 2>&1
```

---

## 9. Tenant storage

`config/tenancy.php` has `suffix_storage_path => true`, so every tenant gets `storage/tenant<id>-/`. The `chown` in §5.3 already covered this. If you serve public tenant files, also run:

```bash
php artisan storage:link
```

Inside tenant Blade/PHP, use `tenant_asset('path/to/file')` instead of `asset()` (already enabled via `asset_helper_tenancy => true`).

---

## 10. Log rotation

Laravel logs to `storage/logs/laravel.log` (and `worker.log`). Add `/etc/logrotate.d/tenancy-starterkit`:

```conf
/var/www/tenancy-starterkit/storage/logs/*.log {
    daily
    missingok
    rotate 14
    compress
    delaycompress
    notifempty
    create 0664 www-data www-data
    sharedscripts
}
```

---

## 11. Redeploy script (every release)

Save as `/var/www/tenancy-starterkit/deploy.sh`, `chmod +x deploy.sh`:

```bash
#!/usr/bin/env bash
set -euo pipefail
cd /var/www/tenancy-starterkit

php artisan down --render="errors::503" --retry=15 || true

git pull --ff-only
composer install --no-dev --optimize-autoloader --no-interaction
npm ci
npm run build

php artisan migrate --force
php artisan tenants:migrate --force

php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
php artisan icons:cache
php artisan filament:cache-components

sudo systemctl reload php8.4-fpm   # flush OPcache (validate_timestamps=0)
php artisan queue:restart

php artisan up
```

Allow the deploy user to reload php-fpm without a password, by adding via `sudo visudo`:

```
deploy ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.4-fpm
```

Run it:

```bash
./deploy.sh
```

---

## 12. Health checks & monitoring

- `https://yourdomain.com/up` — Laravel health endpoint (already enabled in `bootstrap/app.php`).
- `sudo supervisorctl status` — queue worker state.
- `sudo journalctl -u nginx -u php8.4-fpm -u mariadb -u redis-server --since "1 hour ago"` — recent service logs.
- `tail -f storage/logs/laravel.log storage/logs/worker.log` — app + worker logs.
- Optional: install Laravel Pulse or Pail for richer in-app insights.

---

## 13. Custom tenant domains (`family1.com`, `family2.com`, …)

Most multi-tenant SaaS eventually needs to let each tenant bring their own root domain instead of (or in addition to) `<tenant>.yourdomain.com`. This section is for that scenario.

### 13.1 The app already supports it

`app/Services/TenantProvisioningService.php` already writes the tenant's chosen domain (`$tenant->domain_name`) into the `domains` table, and `app/Filament/Pages/PurchasePlan.php` exposes a `custom_domain` field gated by `Plan::support_custom_domain`. `InitializeTenancyByDomain` matches **any** string in the `domains` table — `family1.com` works the same as `family1.yourdomain.com` from tenancy's perspective.

The hard part is **infrastructure** — specifically, getting an SSL cert for a 3rd-party domain you don't own. You cannot pre-issue a wildcard cert that covers `family1.com`. You need TLS issued **per domain, automatically, on first hit**.

### 13.2 Choosing an approach

| Option                            | How it works                                                                                                                            | Effort                                               | Cost           |
| --------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------- | -------------- |
| **A. Caddy + on-demand TLS**      | Caddy issues a Let's Encrypt cert the first time `family1.com` hits the server, after asking your Laravel app "is this domain allowed?" | **Low** — replace Nginx with Caddy + 1 Laravel route | Free           |
| **B. Nginx + per-domain Certbot** | A Laravel job runs `certbot certonly --webroot -d family1.com` and reloads Nginx after a tenant adds a domain                           | Medium — per-domain vhosts, sudo grants, deploy hook | Free           |
| **C. Cloudflare for SaaS**        | Tenants CNAME their domain to your hostname; Cloudflare terminates TLS and forwards traffic                                             | Low (config) but vendor-locked                       | ~$0–7 / domain |

**Recommended for this project: Option A (Caddy).** It replaces §6 Nginx and is by far the cleanest fit for an 8 GB VPS handling tens to hundreds of custom domains.

### 13.3 Option A — Caddy with on-demand TLS

#### Install Caddy

```bash
sudo apt install -y debian-keyring debian-archive-keyring apt-transport-https curl
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' \
  | sudo gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' \
  | sudo tee /etc/apt/sources.list.d/caddy-stable.list
sudo apt update && sudo apt install -y caddy

sudo systemctl stop nginx
sudo systemctl disable nginx
```

#### Add the Caddy "ask" endpoint in Laravel

Caddy hits this endpoint **before** issuing a cert. Returning 2xx allows it; anything else blocks it. This is what stops attackers pointing junk domains at your IP and burning your Let's Encrypt rate limit.

`routes/web.php` (central):

```php
use App\Http\Controllers\CaddyAskController;

Route::get('/caddy/ask', CaddyAskController::class);
```

Create the controller:

```bash
php artisan make:controller CaddyAskController --invokable --no-interaction
```

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stancl\Tenancy\Database\Models\Domain;

class CaddyAskController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $domain = strtolower((string) $request->query('domain'));

        if ($domain === '' || $domain === config('tenancy.central_domain')) {
            return response('', 404);
        }

        $exists = Domain::query()
            ->where('domain', $domain)
            ->whereHas('tenant', fn ($q) => $q->where('is_provisioned', true))
            ->exists();

        return response('', $exists ? 200 : 404);
    }
}
```

> The route lives on the **central** connection and must execute **before** any tenancy middleware. Caddy queries it via the localhost-only listener configured below (`127.0.0.1:8080`), so it never goes through the public TLS path.

#### Caddyfile

`/etc/caddy/Caddyfile`:

```caddyfile
{
    email you@yourdomain.com

    on_demand_tls {
        ask http://127.0.0.1:8080/caddy/ask
        interval 2m
        burst    5
    }
}

# Central + wildcard tenant subdomains (domains you own)
yourdomain.com, *.yourdomain.com {
    tls {
        dns cloudflare {env.CLOUDFLARE_API_TOKEN}   # remove this block if not using Cloudflare DNS
    }
    root * /var/www/tenancy-starterkit/public
    encode gzip zstd
    php_fastcgi unix//run/php/php8.4-fpm.sock
    file_server
}

# Catch-all for tenant custom domains (family1.com, family2.com, …).
# Caddy obtains a Let's Encrypt cert on first request, after the ask hook approves it.
:443 {
    tls {
        on_demand
    }
    root * /var/www/tenancy-starterkit/public
    encode gzip zstd
    php_fastcgi unix//run/php/php8.4-fpm.sock
    file_server
}

# HTTP -> HTTPS for any host
:80 {
    redir https://{host}{uri} permanent
}

# Localhost-only listener used by the on_demand_tls ask hook.
http://127.0.0.1:8080 {
    root * /var/www/tenancy-starterkit/public
    php_fastcgi unix//run/php/php8.4-fpm.sock
    file_server
}
```

If you use Cloudflare DNS for the wildcard cert, expose the API token to Caddy:

```bash
sudo systemctl edit caddy
```

```ini
[Service]
Environment=CLOUDFLARE_API_TOKEN=your_token_here
```

```bash
sudo systemctl restart caddy
sudo systemctl status caddy
```

Caddy persists certs under `/var/lib/caddy/.local/share/caddy/` — outside the app folder, so deploys don't touch them.

#### Tenant onboarding flow

Show the customer these exact steps in the UI when they add a custom domain:

> **Point your domain to our platform**
>
> 1. In your domain registrar's DNS settings, create an **A record**:
>     - **Host**: `@` (apex / root)
>     - **Value**: `YOUR_VPS_IP`
> 2. Optionally create a second A record for `www` with the same value.
> 3. Wait 5–60 minutes for DNS to propagate, then visit `https://family1.com` — your SSL certificate is issued automatically on first visit.

#### DNS verification before saving the domain

Strongly recommended — prevents Caddy from being rate-limited by Let's Encrypt for bogus domains tenants add by mistake. Add to your custom-domain form validation:

```php
use Illuminate\Validation\Rule;

'custom_domain' => [
    'required', 'string', 'regex:/^[a-z0-9.\-]+\.[a-z]{2,}$/i',
    Rule::unique('domains', 'domain'),
    function ($attr, $value, $fail) {
        $ips = @dns_get_record($value, DNS_A);
        $serverIp = config('app.server_ip'); // add SERVER_IP=1.2.3.4 to .env
        if (! collect($ips)->pluck('ip')->contains($serverIp)) {
            $fail("Please point {$value} to {$serverIp} via an A record before continuing.");
        }
    },
],
```

#### Apex-domain limitations (`family1.com` vs `app.family1.com`)

Most registrars allow A records at the apex, so most tenants can point `family1.com` directly. For the small minority that don't, offer two fallbacks in your UI:

- **`www.family1.com` + apex redirect** — A record at apex if possible, plus a registrar-level URL redirect from apex → `www`.
- **`app.family1.com`** — tenant uses a subdomain of their own domain and CNAMEs it to your platform. Most flexible.

Caddy handles all three identically — it issues a cert for whatever hostname arrives, so long as the ask endpoint approves it.

### 13.4 Option B — Sticking with Nginx

Outline for if you must stay on Nginx:

1. Keep the wildcard server block from §6 for `yourdomain.com` + `*.yourdomain.com`.
2. Add a **default** server block to catch any other host:

    ```nginx
    server {
        listen 443 ssl http2 default_server;
        server_name _;
        ssl_certificate     /etc/letsencrypt/live/CURRENT_HOST/fullchain.pem;
        ssl_certificate_key /etc/letsencrypt/live/CURRENT_HOST/privkey.pem;
        root /var/www/tenancy-starterkit/public;
        index index.php;
        location / { try_files $uri $uri/ /index.php?$query_string; }
        location ~ \.php$ {
            fastcgi_pass unix:/run/php/php8.4-fpm.sock;
            fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
            include fastcgi_params;
        }
    }
    ```

3. Build a Laravel job (`IssueCustomDomainCert`) dispatched on `Domain` creation:
    - Verify DNS resolves to your IP.
    - Run `certbot certonly --webroot -w /var/www/tenancy-starterkit/public -d family1.com --non-interactive --agree-tos -m you@yourdomain.com`.
    - Render a per-domain Nginx server block from a Blade template into `/etc/nginx/sites-available/`.
    - `sudo nginx -t && sudo systemctl reload nginx` (requires a `visudo` grant for the deploy user).

4. Configure a Certbot **deploy hook** so Nginx reloads after each renewal:

    ```bash
    sudo mkdir -p /etc/letsencrypt/renewal-hooks/deploy
    echo -e '#!/bin/sh\nsystemctl reload nginx' \
      | sudo tee /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh
    sudo chmod +x /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh
    ```

This works, but it's significantly more moving parts than Caddy: per-domain config files, shell-out from PHP, sudo grants, hook scripts, cert-paths-vs-Nginx-config bookkeeping. Prefer Caddy unless you have a specific reason to stay on Nginx.

---

## Quick gap checklist for this codebase

- `.env` `CENTRAL_DOMAIN` — locally `.test`, in prod set to `yourdomain.com`.
- `app/Providers/Filament/AdminPanelProvider.php` already reads `config('tenancy.central_domain')` for the admin panel domain — no code change needed when swapping environments.
- `database/migrations/tenant/` migrations run automatically for new tenants via the `TenantCreated` event → `MigrateDatabase` job. **This is why the queue worker is mandatory.**
- KVM 2 (2 vCPU / 8 GB) comfortably handles tens to low-hundreds of small tenant DBs on a single box. When you grow past that, move tenants to a dedicated MariaDB server (RDS-equivalent) or shard.

---

## Reference: per-section ownership

| Section | When to touch                                                                    |
| ------- | -------------------------------------------------------------------------------- |
| 0       | First boot; revisit only when rotating SSH keys or users                         |
| 1 – 2   | One-time provisioning; revisit when upgrading PHP/MariaDB major versions         |
| 3 – 4   | One-time DNS + cert; revisit at cert renewal (auto with Cloudflare, ~80d manual) |
| 5       | First deploy; partial re-run when changing `.env` or running new migrations      |
| 6       | One-time Nginx; revisit when adding additional central domains                   |
| 7 – 8   | One-time; revisit when queue or scheduled jobs change                            |
| 9 – 10  | One-time; revisit if you change disks/storage or log retention policy            |
| 11 – 12 | Every deploy / continuous                                                        |
| 13      | When enabling per-tenant custom domains (`family1.com`, `family2.com`, …)        |
