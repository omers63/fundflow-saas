# Production runbook (FundFlow SaaS)

Concise operator checklist after deploy or tenant onboarding.

## Deploy / release

1. Pull release tag on the app server.
2. `composer install --no-dev --optimize-autoloader`
3. `php artisan migrate --force` (central database)
4. `php artisan tenants:migrate --no-interaction`
5. `npm ci && npm run build` (Filament tenant/member/central themes)
6. `php artisan optimize:clear` then `php artisan config:cache` / `route:cache` if you use caching in production
7. Restart queue workers and PHP-FPM (or Sail/containers) so code and config reload

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
