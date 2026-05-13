# Deploying the Tenancy Starter Kit to a Hostinger VPS

A focused checklist for deploying this multi-tenant Laravel app (Laravel 12 + Filament 5 + Livewire 4 + `stancl/tenancy` v3) to a Hostinger VPS.

The two non-negotiables for this project are:

1. **Wildcard subdomain DNS + wildcard SSL** — tenants live on `*.yourdomain.com`.
2. **Switch off SQLite** for the central DB — SQLite is fine locally but doesn't fit per-tenant DB provisioning in production.

---

## 1. VPS image and base packages

On Hostinger VPS, pick **Ubuntu 24.04 + OpenLiteSpeed** or **plain Ubuntu 24.04**. Then install:

| Component      | Version                                                                                                                                        | Why                                                          |
| -------------- | ---------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------ |
| PHP            | **8.4** (≥ 8.2 minimum)                                                                                                                        | `composer.json` requires `^8.2`; local dev is 8.4            |
| PHP extensions | `bcmath, ctype, curl, dom, fileinfo, gd, intl, mbstring, mysql, openssl, pdo, pdo_mysql, redis (phpredis), sqlite3, tokenizer, xml, zip, exif` | Laravel + Filament + tenancy                                 |
| Web server     | Nginx (recommended) or OpenLiteSpeed                                                                                                           |                                                              |
| Database       | **MySQL 8 / MariaDB 10.11**                                                                                                                    | Tenancy expects a real DB user with `CREATE DATABASE` rights |
| Redis          | latest                                                                                                                                         | Cache + queue + sessions; tenancy bootstrapper supports it   |
| Node.js        | 20 LTS                                                                                                                                         | `npm run build` for Vite assets                              |
| Composer       | 2.x                                                                                                                                            |                                                              |
| Supervisor     | latest                                                                                                                                         | Keeps `queue:work` alive                                     |
| Certbot        | latest                                                                                                                                         | Wildcard Let's Encrypt cert (DNS-01)                         |
| Git            |                                                                                                                                                | Pulling code                                                 |

Install on a clean Ubuntu image:

```bash
sudo apt update && sudo apt upgrade -y
sudo add-apt-repository ppa:ondrej/php -y
sudo apt install -y nginx mariadb-server redis-server supervisor git unzip \
  php8.4-fpm php8.4-cli php8.4-mysql php8.4-redis php8.4-mbstring php8.4-xml \
  php8.4-bcmath php8.4-curl php8.4-zip php8.4-gd php8.4-intl php8.4-sqlite3 php8.4-fileinfo
curl -sS https://getcomposer.org/installer | sudo php -- --install-dir=/usr/local/bin --filename=composer
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo bash -
sudo apt install -y nodejs
```

---

## 2. DNS — the critical tenancy bit

In Hostinger's DNS zone editor for `yourdomain.com`:

| Type | Name  | Value    |
| ---- | ----- | -------- |
| A    | `@`   | VPS IPv4 |
| A    | `www` | VPS IPv4 |
| A    | `*`   | VPS IPv4 |

The wildcard `A` record is what lets `acme.yourdomain.com`, `samman.yourdomain.com`, etc. all resolve to your VPS so `InitializeTenancyByDomain` can identify them.

---

## 3. SSL — wildcard certificate

A normal HTTP-01 cert won't cover `*.yourdomain.com`. You need DNS-01 via Certbot:

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot certonly --manual --preferred-challenges=dns \
  -d yourdomain.com -d '*.yourdomain.com'
```

Add the TXT records Certbot prints to Hostinger DNS, then plan for renewal:

- Manual mode requires re-running every ~80 days.
- If you can move DNS to a provider with an API (Cloudflare, Route53, etc.), use the matching Certbot plugin (`certbot-dns-cloudflare`, …) for automated renewal.

---

## 4. Nginx config (catch-all + central)

One server block handles both — tenancy resolves the tenant from `Host`:

```nginx
server {
    listen 443 ssl http2;
    server_name yourdomain.com *.yourdomain.com;

    root /var/www/tenancy-starterkit/public;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/yourdomain.com/privkey.pem;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* { deny all; }
}

server {
    listen 80;
    server_name yourdomain.com *.yourdomain.com;
    return 301 https://$host$request_uri;
}
```

---

## 5. App + tenancy config changes before going live

Edit `.env` on the VPS (do **not** commit it):

```env
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:...           # php artisan key:generate
APP_URL=https://yourdomain.com
CENTRAL_DOMAIN=yourdomain.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=tenancy_central
DB_USERNAME=tenancy
DB_PASSWORD=<strong>

CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1

FILESYSTEM_DISK=local        # or s3
MAIL_MAILER=smtp             # configure real SMTP (Hostinger / Postmark / Resend)
```

Two production-specific things for tenancy:

### 5.1 MySQL user permissions

The `tenancy` user must be able to `CREATE/DROP DATABASE` so `MySQLDatabaseManager` can provision tenant DBs:

```sql
CREATE USER 'tenancy'@'localhost' IDENTIFIED BY '<strong>';
GRANT ALL PRIVILEGES ON *.* TO 'tenancy'@'localhost' WITH GRANT OPTION;
FLUSH PRIVILEGES;
```

If that's too broad, swap in `PermissionControlledMySQLDatabaseManager` (see `config/tenancy.php` near line 66) and grant only what each tenant DB needs.

### 5.2 Switch from SQLite tenant files to MySQL tenant DBs

No code change required — just set MySQL credentials in `.env`. New tenants will get their own MySQL DB named `tenant<id>-`.

---

## 6. Deployment steps (first run)

```bash
cd /var/www
sudo git clone <your-repo> tenancy-starterkit
cd tenancy-starterkit

composer install --no-dev --optimize-autoloader
cp .env.example .env && nano .env   # set values from section 5
php artisan key:generate

sudo chown -R www-data:www-data storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod 775 {} \;

php artisan migrate --force                # central DB
php artisan tenants:migrate --force        # all existing tenants
php artisan db:seed --force                # if you want central seed data

npm ci && npm run build

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
php artisan icons:cache                    # Filament icons
php artisan filament:cache-components
```

---

## 7. Queue worker (Supervisor)

Tenancy enqueues `MigrateDatabase`, `SeedDatabase`, etc. when tenants are created, so the queue **must** be running.

Create `/etc/supervisor/conf.d/tenancy-worker.conf`:

```ini
[program:tenancy-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/tenancy-starterkit/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/tenancy-starterkit/storage/logs/worker.log
stopwaitsecs=3600
```

Then:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start tenancy-worker:*
```

---

## 8. Scheduler (cron)

```cron
* * * * * cd /var/www/tenancy-starterkit && php artisan schedule:run >> /dev/null 2>&1
```

---

## 9. Tenant-storage gotcha

`config/tenancy.php` has `suffix_storage_path => true`, so each tenant gets its own `storage/tenant<id>-/` directory. After deploy, make sure that path is writable:

```bash
sudo chown -R www-data:www-data storage
```

Run `php artisan storage:link` if you plan to serve tenant public files. With tenancy you typically want `tenant_asset()` instead of `asset()` (already enabled via `asset_helper_tenancy => true`).

---

## 10. Subsequent deploys

Minimal deploy script:

```bash
cd /var/www/tenancy-starterkit
php artisan down
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan tenants:migrate --force
php artisan config:cache route:cache view:cache event:cache
php artisan queue:restart
php artisan up
```

---

## Quick gap checklist for this project

- `.env` `CENTRAL_DOMAIN` must match the real domain (locally it's `.test`; in prod set it to `yourdomain.com`).
- `app/Providers/Filament/AdminPanelProvider.php` already reads `config('tenancy.central_domain')`, so changing `CENTRAL_DOMAIN` is enough — no code change needed.
- Tenant migrations under `database/migrations/tenant/` will run automatically for new tenants in production via the `TenantCreated` event → `MigrateDatabase` job. **This is why the queue worker is mandatory.**
- Hostinger's cheapest VPS plans share CPU; if you expect many tenants, pick at least the **KVM 2** tier (2 vCPU / 8 GB) so MySQL + Redis + PHP-FPM + queue worker don't compete for resources.

---

## Reference: per-section ownership

| Section | Owner / when to touch                                                                 |
| ------- | ------------------------------------------------------------------------------------- |
| 1       | One-time VPS provisioning                                                             |
| 2 – 3   | One-time DNS + cert; re-run cert renewal every ~80 days if manual                     |
| 4       | One-time Nginx; revisit if you add additional central domains                         |
| 5       | Each environment change (staging vs prod) and when adding new services (S3, Redis, …) |
| 6       | First deploy only                                                                     |
| 7 – 8   | One-time; revisit when queue or scheduled jobs change                                 |
| 9       | One-time; revisit if you change disks or move to S3                                   |
| 10      | Every deploy                                                                          |
