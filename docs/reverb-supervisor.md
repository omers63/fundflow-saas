# Reverb under Supervisor — install & operations

How to run Laravel **Reverb** (`fundflow-reverb`) as a persistent service on Ubuntu, so Filament’s notification bell can push over WebSockets.

**Related:** `deploy/supervisor/fundflow-reverb.conf`, `docs/production-runbook.md`, `docs/filament-notification-bell-and-reverb.md`.

---

## What this service does

| Component | Role |
|-----------|------|
| **Laravel app** | With `BROADCAST_CONNECTION=reverb`, sends events to `http://127.0.0.1:8080/apps/{id}/events` |
| **Reverb** (`fundflow-reverb`) | Listens on `REVERB_SERVER_HOST:REVERB_SERVER_PORT` (default `127.0.0.1:8080`) |
| **Nginx** | Proxies browser `wss://<domain>/app/...` → `127.0.0.1:8080` |
| **Filament Echo** | Connects in the browser; bell updates in real time (polling every 10s remains as fallback) |

If Reverb is down, you may see in `storage/logs/laravel.log`:

```text
Pusher error: cURL error 7: Failed to connect to 127.0.0.1 port 8080 ... Couldn't connect to server
```

---

## One-time server setup

### 1. Install Supervisor

```bash
sudo apt update
sudo apt install -y supervisor
sudo systemctl enable supervisor
sudo systemctl start supervisor
sudo systemctl status supervisor
```

### 2. Redis + PHP redis extension (required for this app)

Reverb’s start command uses Laravel’s cache (e.g. graceful restart). This project uses `CACHE_STORE=redis` for Stancl tenancy tagged cache.

```bash
sudo apt install -y redis-server php8.4-redis
sudo systemctl enable redis-server
sudo systemctl start redis-server
redis-cli ping    # expect PONG
php -m | grep redis   # expect "redis"
```

### 3. Application `.env` (minimum for Reverb)

```env
BROADCAST_CONNECTION=reverb
FILAMENT_DATABASE_NOTIFICATIONS_POLLING=10s

REVERB_APP_ID=...
REVERB_APP_KEY=...
REVERB_APP_SECRET=...
REVERB_HOST=fundflow-saas.osamman.com
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_SERVER_HOST=127.0.0.1
REVERB_SERVER_PORT=8080
REVERB_SERVER_SCHEME=http

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"

CACHE_STORE=redis
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

After changing `.env`:

```bash
cd /var/www/fundflow-saas
php artisan config:clear
# use config:cache in production only when you normally cache config
```

### 4. Frontend assets

```bash
cd /var/www/fundflow-saas
npm ci && npm run build
```

### 5. Nginx WebSocket proxy

See `docs/production-runbook.md` (Reverb section) for a `location /app/` block pointing to `127.0.0.1:8080`.

---

## Install the `fundflow-reverb` Supervisor program

From the app root on the server:

```bash
cd /var/www/fundflow-saas

# Ensure log file is writable by www-data (Supervisor runs as www-data)
touch storage/logs/reverb.log
sudo chown www-data:www-data storage/logs/reverb.log

# Install unit (path must match your deploy directory)
sudo cp deploy/supervisor/fundflow-reverb.conf /etc/supervisor/conf.d/fundflow-reverb.conf

# Load and start
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start fundflow-reverb
```

**Expected status:**

```bash
sudo supervisorctl status fundflow-reverb
# fundflow-reverb    RUNNING   pid ..., uptime ...
```

---

## Useful commands (cheat sheet)

### Supervisor — `fundflow-reverb`

| Action | Command |
|--------|---------|
| Status | `sudo supervisorctl status fundflow-reverb` |
| Start | `sudo supervisorctl start fundflow-reverb` |
| Stop | `sudo supervisorctl stop fundflow-reverb` |
| Restart (after deploy / `.env`) | `sudo supervisorctl restart fundflow-reverb` |
| Reload config after editing `.conf` | `sudo supervisorctl reread && sudo supervisorctl update` |
| Tail logs | `tail -f /var/www/fundflow-saas/storage/logs/reverb.log` |
| All programs | `sudo supervisorctl status` |

### Laravel Reverb

| Action | Command |
|--------|---------|
| Graceful restart (signals running server) | `cd /var/www/fundflow-saas && php artisan reverb:restart` |
| Clear config after `.env` change | `php artisan config:clear` |
| **Do not** run in production (Supervisor owns the process) | `php artisan reverb:start` in an SSH session |

Use **`reverb:restart`** when Reverb is already supervised; use **`supervisorctl restart fundflow-reverb`** after code deploy or if the process crashed.

### Verify Reverb is listening

```bash
ss -tlnp | grep 8080
# LISTEN ... 127.0.0.1:8080 ... users:(("php",pid=...,...))

pgrep -af 'artisan reverb'
# should show one reverb:start under Supervisor
```

### Redis

```bash
redis-cli ping
sudo systemctl status redis-server
```

### Nginx

```bash
sudo nginx -t
sudo systemctl reload nginx
```

### Deploy follow-up (typical)

```bash
cd /var/www/fundflow-saas
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan tenants:migrate --no-interaction
npm ci && npm run build
php artisan optimize:clear
sudo supervisorctl restart fundflow-reverb
sudo systemctl reload php8.4-fpm
```

---

## Troubleshooting

| Symptom | Likely cause | Fix |
|---------|----------------|-----|
| `Address already in use` on 8080 | Manual `reverb:start` while Supervisor already runs | `sudo supervisorctl status fundflow-reverb`; stop stray `php artisan reverb` PIDs; use Supervisor only |
| `Couldn't connect to 127.0.0.1 port 8080` | Reverb not running | `sudo supervisorctl start fundflow-reverb` |
| `Class "Redis" not found` | Missing `php8.4-redis` | `sudo apt install php8.4-redis` and restart PHP-FPM |
| `redis-cli ping` fails | Redis down | `sudo systemctl start redis-server` |
| Echo errors in browser; bell still updates every ~10s | nginx WS proxy or stale Vite build | Fix `/app/` proxy; `npm run build`; hard refresh |
| `FATAL` in Supervisor log | Wrong path, permissions, or PHP error | `tail -50 storage/logs/reverb.log`; check `user=www-data` can read `.env` and `storage/` |

**Temporary disable broadcasting** (polling-only bell):

```env
BROADCAST_CONNECTION=log
```

```bash
php artisan config:clear
sudo supervisorctl stop fundflow-reverb   # optional
```

---

## Unit file reference

File: `deploy/supervisor/fundflow-reverb.conf`

| Setting | Value |
|---------|--------|
| Program name | `fundflow-reverb` |
| Command | `php /var/www/fundflow-saas/artisan reverb:start --no-interaction` |
| User | `www-data` |
| Autostart / autorestart | `true` |
| Log | `/var/www/fundflow-saas/storage/logs/reverb.log` |

Edit the `command` / `directory` paths if the app is not deployed under `/var/www/fundflow-saas`.

---

## Do not run two Reverb processes

- **Production:** only Supervisor should run `reverb:start`.
- A one-off `php artisan reverb:start` in SSH will bind port 8080 and block Supervisor (or the next deploy restart).
- If you need a clean slate:

```bash
sudo supervisorctl stop fundflow-reverb
# only if a stray process remains:
pgrep -af 'artisan reverb'
# kill <pid> if needed
sudo supervisorctl start fundflow-reverb
```
