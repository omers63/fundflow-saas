# Deploy assets

Server-side configuration templates for production.

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

`queue:ensure-worker` runs **every minute** via the Laravel scheduler. It uses `pgrep` to detect a `queue:work` process for this app; if none is found, it runs `queue:restart` and starts `queue:work` in the background.

- Listed in **Automation → Scheduled jobs** as **Ensure queue worker**
- Config: `config/queue.php` → `worker_watchdog` (env: `QUEUE_WORKER_WATCHDOG_ENABLED`, `QUEUE_WORKER_CONNECTION`, etc.)
- **Disable** (`QUEUE_WORKER_WATCHDOG_ENABLED=false`) when Supervisor already manages `queue:work`, to avoid duplicate workers
