# Deploy assets

Server-side configuration templates for production.

## Nginx + PHP-FPM — production hardening

| File | Purpose |
|------|---------|
| `nginx/fundflow-saas.conf` | SaaS vhost: PHP 8.4, HTTP/2, 25 MB body, Reverb `/app/`, FastCGI timeouts |
| `nginx/reverb-app-location.conf` | WebSocket location snippet only |
| `php/99-fundflow-production.ini` | FPM memory 256 M, uploads 20 M, opcache |
| `php/php8.4-fpm-pool-www.conf` | Pool reference (`pm.max_children=20`, recycle, slowlog) |

Apply (as root) after reviewing:

```bash
cd /var/www/fundflow-saas
sudo cp deploy/nginx/fundflow-saas.conf /etc/nginx/sites-available/fundflow-saas
sudo ln -sfn /etc/nginx/sites-available/fundflow-saas /etc/nginx/sites-enabled/fundflow-saas
# merge pool keys from deploy/php/php8.4-fpm-pool-www.conf into /etc/php/8.4/fpm/pool.d/www.conf
sudo cp deploy/php/99-fundflow-production.ini /etc/php/8.4/fpm/conf.d/
sudo nginx -t && sudo systemctl reload nginx
sudo systemctl reload php8.4-fpm
```

**Hostnames:** SaaS stays on `fundflow-saas.osamman.com` (and tenant `*.fundflow-saas.osamman.com`). Apex `osamman.com` / `www.osamman.com` remains on **legacy** fundflow (and cashflow on `*.osamman.cloud` / `cashflow.osamman.com`) — do not steal the apex for SaaS.

Global nginx: prefer `ssl_protocols TLSv1.2 TLSv1.3;` only (see `/etc/nginx/nginx.conf`).

Backups from the 2026-08-04 hardening: `/root/config-backups/nginx-php-hardening-*`.

Also see **`docs/production-runbook.md`**.

## Supervisor — `fundflow-reverb`

| File | Service |
|------|---------|
| `supervisor/fundflow-reverb.conf` | Laravel Reverb (`php artisan reverb:start`) |

**Full guide (install, commands, troubleshooting):** [`docs/reverb-supervisor.md`](../docs/reverb-supervisor.md)

### Quick install

```bash
# One-time: Supervisor + Redis (if not already installed)
sudo apt update
sudo apt install -y supervisor redis-server php8.4-redis
sudo systemctl enable supervisor redis-server

cd /var/www/fundflow-saas
touch storage/logs/reverb.log
sudo chown www-data:www-data storage/logs/reverb.log
sudo cp deploy/supervisor/fundflow-reverb.conf /etc/supervisor/conf.d/
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start fundflow-reverb
sudo supervisorctl status fundflow-reverb
```

### Quick Queue/Reverb/PHP re-deployment

```bash
# After PHP/code deploys, restart workers so they load new job classes:
sudo supervisorctl restart fundflow-queue
sudo supervisorctl restart fundflow-reverb
sudo systemctl reload php8.4-fpm
```

Queue workers keep old class code in memory until restarted (`queue:restart` or Supervisor restart). Without that, jobs like reconciliation keep using the previous notification path.

### Quick operations

```bash
sudo supervisorctl status fundflow-reverb
sudo supervisorctl restart fundflow-reverb
tail -f /var/www/fundflow-saas/storage/logs/reverb.log
cd /var/www/fundflow-saas && php artisan reverb:restart
ss -tlnp | grep 8080
```

Also see **`docs/production-runbook.md`** (Reverb section) for nginx WebSockets and deploy checklist.

## Cron — Laravel scheduler

| File | Purpose |
|------|---------|
| `cron/fundflow-scheduler` | Runs `php artisan schedule:run -q` every minute as `www-data` (errors only in `scheduler.log`) |
| `logrotate/fundflow-scheduler` | Weekly / 5MB rotate for `storage/logs/scheduler.log` |

**Full guide:** [`docs/production-runbook.md`](../docs/production-runbook.md#scheduled-fund-jobs)

### Quick install

```bash
cd /var/www/fundflow-saas
touch storage/logs/scheduler.log
sudo chown www-data:www-data storage/logs/scheduler.log
sudo cp deploy/cron/fundflow-scheduler /etc/cron.d/fundflow-scheduler
sudo chmod 644 /etc/cron.d/fundflow-scheduler
sudo chown root:root /etc/cron.d/fundflow-scheduler
sudo cp deploy/logrotate/fundflow-scheduler /etc/logrotate.d/fundflow-scheduler
sudo chmod 644 /etc/logrotate.d/fundflow-scheduler
```

Verify:

```bash
cat /etc/cron.d/fundflow-scheduler
cd /var/www/fundflow-saas && php artisan schedule:list
# After ~1 minute (normally empty when quiet):
tail -n 20 storage/logs/scheduler.log
```

Scheduler logging is quiet by default (`-q`). For temporary verbose output, edit the cron line and remove `-q`.

### Log file ownership

PHP-FPM runs as `www-data`. If `storage/logs/laravel.log` is owned by `root` (e.g. after `sudo php artisan …`), web requests that log (including web-push delivery) can 500 with Filament’s “Error while loading page”. Prefer:

```bash
sudo -u www-data php artisan …
# or fix ownership:
sudo chown -R www-data:www-data storage/logs
sudo chmod 2775 storage/logs
```

## Queue worker watchdog

`queue:ensure-worker` is scheduled **only when** `QUEUE_WORKER_WATCHDOG_ENABLED=true`. It uses `pgrep` to detect a `queue:work` process for this app and starts one in the background if missing. It does **not** call `queue:restart` (that would bounce Supervisor-managed workers every minute on a false miss).

- Listed in **Automation → Scheduled jobs** as **Ensure queue worker**
- Config: `config/queue.php` → `worker_watchdog` (env: `QUEUE_WORKER_WATCHDOG_ENABLED`, `QUEUE_WORKER_CONNECTION`, etc.)
- **Keep disabled** (default) when Supervisor already manages `queue:work` — this production host uses `deploy/supervisor/fundflow-queue.conf`
