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
| `cron/fundflow-scheduler` | Runs `php artisan schedule:run` every minute as `www-data` |

**Full guide:** [`docs/production-runbook.md`](../docs/production-runbook.md#scheduled-fund-jobs)

### Quick install

```bash
cd /var/www/fundflow-saas
touch storage/logs/scheduler.log
sudo chown www-data:www-data storage/logs/scheduler.log
sudo cp deploy/cron/fundflow-scheduler /etc/cron.d/fundflow-scheduler
sudo chmod 644 /etc/cron.d/fundflow-scheduler
sudo chown root:root /etc/cron.d/fundflow-scheduler
```

Verify:

```bash
cat /etc/cron.d/fundflow-scheduler
cd /var/www/fundflow-saas && php artisan schedule:list
# After ~1 minute:
tail -n 20 storage/logs/scheduler.log
```

## Queue worker watchdog

`queue:ensure-worker` runs **every minute** via the Laravel scheduler. It uses `pgrep` to detect a `queue:work` process for this app; if none is found, it runs `queue:restart` and starts `queue:work` in the background.

- Listed in **Automation → Scheduled jobs** as **Ensure queue worker**
- Config: `config/queue.php` → `worker_watchdog` (env: `QUEUE_WORKER_WATCHDOG_ENABLED`, `QUEUE_WORKER_CONNECTION`, etc.)
- **Disable** (`QUEUE_WORKER_WATCHDOG_ENABLED=false`) when Supervisor already manages `queue:work`, to avoid duplicate workers
