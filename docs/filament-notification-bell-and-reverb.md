# Filament notification bell & Reverb — session notes

Documentation of the assistant responses for the **last three prompts** in the deposit-notification / real-time bell workstream (May 2026).

---

## 1. Asynchronous, more frequent bell refresh

**User prompt:** The notification bell needs to refresh asynchronously and more frequently to show new notifications.

### Summary

The bell was updated so it polls more often, loads the badge count on page load, and refreshes immediately after relevant admin actions on the same page.

### Polling (default 5 seconds)

- **Tenant** and **member** panels use `databaseNotificationsPolling()` driven by config (initially **5s**; Filament’s default was ~30s).
- Tunable via `FILAMENT_DATABASE_NOTIFICATIONS_POLLING` in `.env` or `config/fundflow.php` (`database_notifications_polling`).

### Faster first load

- `databaseNotifications(isLazy: false)` so the **badge count** appears when the page loads, not only after opening the bell.

### Instant refresh after actions

- After deposit/cash-out accept/reject on admin list pages, the bell refreshes via Livewire (`$refresh()` on the `DatabaseNotifications` component), same pattern as insights widgets.
- A listener on `NotificationSent` (database channel) can dispatch Filament’s `DatabaseNotificationsSent` when broadcasting is enabled later.

### Key files

| File | Role |
|------|------|
| `app/Filament/Support/DatabaseNotificationsRefresh.php` | Polling interval helper + dispatch refresh/broadcast |
| `config/fundflow.php` | `FILAMENT_DATABASE_NOTIFICATIONS_POLLING` |
| `app/Providers/Filament/TenantPanelProvider.php` | Panel bell config |
| `app/Providers/Filament/MemberPanelProvider.php` | Panel bell config |
| `app/Providers/AppServiceProvider.php` | `NotificationSent` → broadcast hook |
| `app/Filament/Tenant/Resources/FundPostings/FundPostingResource.php` | Refresh after accept/reject |
| `app/Filament/Tenant/Resources/CashOutRequests/CashOutRequestResource.php` | Refresh after accept/reject |

### Operational note

When a **member** submits a deposit, **admins on another browser** still depended on polling (up to ~5s) until Echo/Reverb was enabled (see prompt 2).

---

## 2. Enable Filament broadcasting / Echo + 10s polling

**User prompt:** Enable Filament broadcasting/Echo and set `FILAMENT_DATABASE_NOTIFICATIONS_POLLING=10s` in `.env`.

### Summary

Laravel Reverb was installed and wired for Filament’s Echo client. Polling was set to **10 seconds** as a fallback when websockets are unavailable.

### Configuration

- **`composer require laravel/reverb`**
- Published: `config/broadcasting.php`, `config/reverb.php`, `config/filament.php`, `routes/channels.php`
- **`config/filament.php`** — Echo client with `broadcaster: reverb` and `VITE_REVERB_*` env vars
- **`bootstrap/app.php`** — `withBroadcasting()` for `/broadcasting/auth` with Filament session auth + tenancy middleware (`InitializeTenancyByDomainEarly`, etc.)
- **`routes/channels.php`** — private channels:
  - `App.Models.Tenant.User.{id}`
  - `App.Models.Central.User.{id}`
- **`App\Events\DatabaseNotificationsSentNow`** — extends Filament’s `DatabaseNotificationsSent` with `ShouldBroadcastNow` (no queue delay for bell updates)
- **`.env` / `.env.example`**:
  - `BROADCAST_CONNECTION=reverb`
  - `FILAMENT_DATABASE_NOTIFICATIONS_POLLING=10s`
  - Reverb app credentials, public host (`REVERB_HOST`), server bind (`REVERB_SERVER_HOST=127.0.0.1`, `REVERB_SERVER_PORT=8080`)
  - `VITE_REVERB_*` for frontend build

### Production services required

1. **Reverb process** (persistent) — use Supervisor program **`fundflow-reverb`** (see [`docs/reverb-supervisor.md`](reverb-supervisor.md)):
   ```bash
   sudo supervisorctl start fundflow-reverb
   ```
   Do not leave a manual `php artisan reverb:start` running in SSH alongside Supervisor.

2. **Reverse proxy** — Browsers connect to `wss://<host>:443`; Reverb listens on `127.0.0.1:8080`. Nginx (or similar) must proxy WebSocket traffic to that port.

3. **Queue worker** — Still needed for other queued jobs (`QUEUE_CONNECTION=database`). Bell pushes use `ShouldBroadcastNow` and do not wait on the queue.

4. **Frontend** — `npm run build` (or `dev`) so `VITE_REVERB_*` are baked into assets.

### Verify

After `php artisan config:clear`, load admin/member panel and check the browser console — Echo should connect when Reverb is running. If websockets fail, the bell still refreshes every **10 seconds** via Livewire polling.

### Tests

`tests/Feature/Tenant/MemberPortalTest.php` — asserts member panel has database notifications, non-lazy load, 10s polling, broadcasting enabled, Reverb broadcaster in config.

---

## 3. `reverb:start` — `Class "Redis" not found`

**User prompt:** Terminal output from `php artisan reverb:start` failing with:

```
Class "Redis" not found
at vendor/laravel/framework/.../PhpRedisConnector.php:80
```

### Root cause

Laravel booted with **`CACHE_STORE=redis`** and **`REDIS_CLIENT=phpredis`**, but the **PHP `redis` extension** was not installed. Reverb’s start command uses the cache driver early (e.g. graceful-restart via `Cache::get('laravel:reverb:restart')`), so the process failed before binding to the WebSocket port.

This is separate from:

- The **Redis server** (daemon on port 6379)
- Reverb’s **react-redis** client used for horizontal scaling pub/sub when `REVERB_SCALING_ENABLED=true`

Stancl tenancy also expects Redis for **tagged tenant cache** when `CacheTenancyBootstrapper` is active (see `.env.example` comment on `CACHE_STORE=redis`).

### Fix applied on server

1. Installed **`php8.4-redis`** and **`redis-server`**
2. Verified: `php -m` lists `redis`; `redis-cli ping` → `PONG`
3. Ran `php artisan config:clear`

After that, `reverb:start` passed the Redis bootstrap error.

### Follow-up: port already in use

Next attempt reported:

```
Failed to listen on "tcp://127.0.0.1:8080": Address already in use
```

An existing process was already running `php artisan reverb:start` on port **8080**. No second instance is needed unless you intentionally restart.

**Restart options:**

```bash
php artisan reverb:restart   # signal running server
# or stop Supervisor unit / kill PID, then:
php artisan reverb:start
```

### Recommendations

| Setting | Recommendation |
|---------|----------------|
| `REVERB_SCALING_ENABLED` | Leave **false** unless running multiple Reverb nodes |
| `CACHE_STORE=redis` | Keep only if `php8.4-redis` + `redis-server` are installed |
| Nginx | Proxy WebSockets to `127.0.0.1:8080` |
| Assets | Rebuild with `npm run build` so Echo uses `VITE_REVERB_*` |

---

## Related work (same session, earlier prompts)

For context, these changes preceded the three prompts above:

- **Readable deposit notifications** — `FundPostingNotificationFormatter`, HTML sections, `dir="auto"` / bidi for Arabic names (`resources/css/filament/mobile-panels.css`).
- **Route fix** — Admin notification URLs use `panel: 'tenant'`; member URLs use `panel: 'member'` (avoids `filament.member.resources.fund-postings.index` not defined).
- **Deposit notifications** — Admins on new deposit; members on accept/reject with settlement breakdown; Filament `format: filament` required for bell visibility.

---

## Quick reference — env vars

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

Requires **php8.4-redis** extension and **redis-server** when using Redis for cache.
