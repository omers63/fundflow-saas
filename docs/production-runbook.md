# Production runbook (FundFlow SaaS)

Concise operator checklist after deploy or tenant onboarding.

## Deploy / release

1. Pull release tag on the app server.
2. `composer install --no-dev --optimize-autoloader`
3. `php artisan migrate --force` (central database)
4. `php artisan tenants:migrate --no-interaction`
5. `npm ci && npm run build` (Filament tenant/member/central themes)
6. `php artisan optimize:clear` then `php artisan config:cache` / `route:cache` if you use caching in production
7. Restart **queue workers**, **Reverb**, and PHP-FPM (or Sail/containers) so code and config reload:
   ```bash
   sudo supervisorctl restart fundflow-reverb
   # sudo supervisorctl restart fundflow-queue:*   # if you use a queue worker unit
   sudo systemctl reload php8.4-fpm
   ```

## Reverb & real-time notifications

The admin/member **notification bell** can push over WebSockets when `BROADCAST_CONNECTION=reverb`. Laravel sends events to Reverb at **`127.0.0.1:8080`** (`REVERB_SERVER_*` in `.env`). Browsers connect via **`wss://<CENTRAL_DOMAIN>`** (`REVERB_HOST` / `VITE_REVERB_*`).

### Prerequisites

| Requirement | Notes |
|-------------|--------|
| **Redis** | `CACHE_STORE=redis` needs `php8.4-redis` + `redis-server` (Reverb start uses cache; Stancl tagged tenant cache). |
| **`.env`** | `BROADCAST_CONNECTION=reverb`, Reverb app keys, `REVERB_SERVER_HOST=127.0.0.1`, `REVERB_SERVER_PORT=8080`, `FILAMENT_DATABASE_NOTIFICATIONS_POLLING=10s` (fallback polling). |
| **Frontend build** | `npm run build` so `VITE_REVERB_*` are in the manifest. |
| **Nginx** | Proxy WebSocket upgrades from public HTTPS to `127.0.0.1:8080` (see below). |

If Reverb is not running, the bell still updates every **10s** via Livewire polling, but logs may show `Pusher error: cURL error 7 ... Couldn't connect to 127.0.0.1 port 8080`.

### Supervisor (Reverb)

**Operator guide:** [`docs/reverb-supervisor.md`](reverb-supervisor.md) — install Supervisor, Redis/phpredis, install `fundflow-reverb`, command cheat sheet, troubleshooting.

Use the unit file in the repo (do **not** run a second manual `reverb:start` in SSH — that causes `Address already in use` on 8080):

```bash
sudo apt install -y supervisor redis-server php8.4-redis   # one-time
sudo cp /var/www/fundflow-saas/deploy/supervisor/fundflow-reverb.conf /etc/supervisor/conf.d/
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl start fundflow-reverb
sudo supervisorctl status fundflow-reverb
```

Logs: `storage/logs/reverb.log` — `tail -f storage/logs/reverb.log`

**After code or `.env` changes:** `php artisan config:clear` then `sudo supervisorctl restart fundflow-reverb`

**Graceful signal to running server:** `php artisan reverb:restart`

### Nginx WebSocket proxy (example)

Add a `location` on the same `server` block that serves the app (adjust `server_name` / TLS paths). Reverb must stay bound to localhost only:

```nginx
location /app/ {
    proxy_http_version 1.1;
    proxy_set_header Host $http_host;
    proxy_set_header Scheme $scheme;
    proxy_set_header SERVER_PORT $server_port;
    proxy_set_header REMOTE_ADDR $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "Upgrade";
    proxy_pass http://127.0.0.1:8080;
}
```

Reload nginx: `sudo nginx -t && sudo systemctl reload nginx`

Filament Echo uses `/broadcasting/auth` on the main app for private channels; the `/app/` path is for the Reverb/Pusher protocol.

### Smoke checks

```bash
ss -tlnp | grep 8080          # should show php (reverb)
redis-cli ping                # PONG
sudo supervisorctl status fundflow-reverb
```

In the browser (admin or member panel): DevTools → Network → WS — expect a connection to your domain without errors when Reverb and nginx are correct.

### Troubleshooting

| Log / symptom | Cause | Action |
|---------------|--------|--------|
| `Address already in use` on 8080 | Second `reverb:start` or stray process | `sudo supervisorctl status fundflow-reverb`; stop manual duplicates; use `supervisorctl restart fundflow-reverb` only. |
| `Couldn't connect to 127.0.0.1 port 8080` | Reverb not running | `sudo supervisorctl start fundflow-reverb` |
| `Class "Redis" not found` | Missing PHP redis extension | `sudo apt install php8.4-redis redis-server` |
| Echo fails in browser, polling works | nginx WS proxy or `VITE_REVERB_*` / build | Fix proxy; `npm run build`; hard refresh |

To disable broadcasting temporarily (polling only): set `BROADCAST_CONNECTION=log`, then `php artisan config:clear`.

More detail: `docs/filament-notification-bell-and-reverb.md`.

## Scheduled fund jobs

Ensure the host cron runs Laravel’s scheduler every minute:

```bash
* * * * * cd /var/www/fundflow-saas && php artisan schedule:run >> /dev/null 2>&1
```

Review **System → Jobs & commands** in the tenant admin panel for the catalog, last run times, and manual runs (respects `BatchPostingGate`).

## Tenant admin URLs (typical)

| Task | Path |
|------|------|
| Dashboard | `/admin` |
| Contribution cycles | Use nav **Fund Management → Contribution cycles** (Filament-discovered slug) |
| **Migration workflow** | `/admin/migration-workflow` |
| Reconciliation | `/admin/reconciliation-exceptions` |
| Jobs | `/admin/jobs` |

After navigation changes, run `php artisan optimize:clear` if menus look stale.

## Migration onboarding (legacy members)

1. Open **Fund Management → Migration workflow** (or **Members → Migration workflow**).
2. **Not started** tab → **Begin migration** (cutoff date) or use **Migration → Generate migration stubs** on the member profile.
3. On the member profile: **Migration cycles** tab — classify stubs, lump-sum / instalment / OB offset settlements.
4. **Migration → Post opening balances**, then **Clear for active operation** (or grant partial clearance when escalated cycles remain open).

## Smoke checks

```bash
php artisan test --compact tests/Feature/Tenant/ComplianceLayerTest.php tests/Feature/Tenant/MigrationWorkflowPageTest.php
```

Verify nightly reconciliation and contribution window commands appear healthy on **Jobs & commands**.
