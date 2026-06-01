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
