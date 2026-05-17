# Implementation status

Last updated from agent session work on loan cluster UX, deposits naming, loan insights, and delinquency workflows.

**Related docs**

- [loan-delinquency-workflow.md](./loan-delinquency-workflow.md) — technical design for delinquency, late fees, guarantor liability, and scheduled jobs
- [prompts.txt](./prompts.txt) — broader product backlog (bank imports, theming, tables, membership, legacy migration, etc.)

---

## Completed

### Loan & fund tier tables (tenant)

| Item | Detail |
|------|--------|
| Column manager | `columnManager(true)` on fund tiers and loan tiers tables |
| Create action | **New Fund Tier** / **New Loan Tier** moved from page header to table `headerActions()` |
| List pages | `getHeaderActions()` on list pages adjusted so create lives on the table |

**Key files:** `FundTiersTable.php`, `LoanTiersTable.php`, `ListFundTiers.php`, `ListLoanTiers.php`

### Loan tables — column manager (tenant & member)

`columnManager(true)` enabled on:

- Loans list (`LoansTable`)
- Loan queue (`ListLoanQueue`)
- Installments, disbursements, repayments relation managers (tenant loan view)
- Member **My loans** list and installments relation manager
- Member loans relation manager on member profile

### Loan insights widgets

| Context | Widget | Pages |
|---------|--------|--------|
| Tenant | `LoanInsightsWidget` + blade partials | Portfolio, queue, loan tiers, fund tiers, loan view/edit |
| Tenant | `LoanViewInsights` | Loan detail |
| Member | `MemberLoanInsightsWidget` / `MyLoanViewInsights` | My loans list, my loan view |

**Service:** `LoanInsightsService` (snapshots: `portfolio`, `queue`, `loan_tiers`, `fund_tiers`, `loan_detail`, `member_portfolio`, `delinquency`)

**Tests:** `tests/Feature/Tenant/LoanInsightsServiceTest.php`

**Refresh:** `LoanResource::dispatchInsightsRefresh()` after mutating loan actions

### Loan insights widget bug fix

| Issue | Fix |
|-------|-----|
| Sections disappeared after ~15s | Removed `wire:poll` on insights widget |
| Wrong context after refresh | `LoanInsightsWidget::resolveContext()` from route name (and `?tab=` for queue) |
| KPI animation | CSS animation uses `forwards` instead of `both` |

### Navigation rename: Deposits

| Before | After |
|--------|--------|
| Posted Funds / Fund postings | **Deposits** (tenant + member nav, notifications, related copy) |

**Key files:** `FundPostingResource`, `MyFundPostingResource`, `FundPostingsTable`, services/notifications, `lang/ar.json`

### Delinquency — backend & automation

| Item | Detail |
|------|--------|
| Core service | `LoanDelinquencyService` — mark overdue, sync member `delinquent`, guarantor liability transfer/restore |
| Daily job | `loans:check-defaults` runs mark overdue → sync members → warnings / guarantor debits (`LoanDefaultService`) |
| Overdue status | Pending installments past cycle deadline → `overdue` + late fee (was never set before) |
| Guarantor liability | `guarantor_liability_transferred_at` set from admin UI; immediate guarantor collection path when set |

**Key files:** `LoanDelinquencyService.php`, `LoanDefaultService.php`, `LoansCheckDefaultsCommand.php`, `routes/console.php`

### Delinquency — tenant UI

| Item | Detail |
|------|--------|
| Workspace | **Loans → Delinquency** — 3 tabs: Overdue installments, Contribution arrears, Guarantor exposure |
| Cluster nav | `DelinquencyPage.php` with badge (overdue count) |
| Header actions | Run delinquency check, Mark overdue only, Send admin digest |
| Loan actions | Transfer liability to guarantor, Restore borrower liability |
| Loan view | Guarantor liability + late repayment count on summary |
| Insights | `delinquency` context widget |

**Key files:** `ListDelinquency.php`, `LoanFilamentActions.php`, `ViewLoan.php`, `LoanResource.php`

### Delinquency — follow-ups (completed)

| Item | Detail |
|------|--------|
| Contribution arrears table | One row per **member + period**; `records()` not aggregated `HtmlString` |
| `joined_at` | Periods before member joined excluded |
| Contribution status | `missing` / `pending` / `failed` badges; only `posted` clears arrears |
| Tab UX | `wire:key` per tab + `getTableQueryStringIdentifier()` |
| Member admin | `MemberDelinquencyActions` on member view + delinquency infolist section |
| Admin digest | `DelinquencyDigestNotification` (database + **mail** when admin has email), `delinquency:send-digest` (daily 07:30), manual send on delinquency page |
| Contribution arrears filter | Per-member `SelectFilter` on contributions tab (Filament `filters` + `records($filters)`) |
| Member banner | `MemberArrearsAlert` on member dashboard when member has arrears |

**Tests:** `LoanDelinquencyServiceTest.php` (7), `DelinquencyDigestServiceTest.php` (3) — **10 tests passing**

**Localization:** Arabic keys added in `lang/ar.json` for delinquency-related UI

### Documentation

| File | Purpose |
|------|---------|
| [loan-delinquency-workflow.md](./loan-delinquency-workflow.md) | End-to-end delinquency design, mermaid flow, follow-up summary |
| This file | Completed vs remaining checklist |

---

## Remaining (from this conversation thread)

These were discussed or listed as optional; **not implemented** unless noted otherwise.

### Delinquency — optional enhancements

| Item | Notes |
|------|--------|
| ~~Email channel for digest~~ | Done — `mail` channel when admin `email` is filled |
| ~~Per-member filter on contribution arrears tab~~ | Done — `SelectFilter::make('member_id')` |
| Re-enable insights polling | Only if `context` stays route-stable (no default `portfolio` on refresh) |
| Production verification | Run `loans:check-defaults` / `delinquency:send-digest` in staging; confirm cron on server |
| Git commit / PR | Not requested in session; code may be uncommitted |

### Prompt #34 — tenant main dashboard (done)

| Item | Detail |
|------|--------|
| Custom dashboard | `App\Filament\Tenant\Pages\Dashboard` + `TenantDashboardWidget` |
| Data | `TenantDashboardService` (greeting, quick actions, gauges, charts, workspace links) |
| Replaces | `FundOverview` on home (`isDiscovered = false`) |

**Tests:** `tests/Feature/Tenant/TenantDashboardServiceTest.php`

### Prompt #33 — explicit ask (now addressed)

> Mechanisms for tracking late contributions, loan repayment installments, loan delinquency, and moving liability to guarantor

**Status:** Implemented (see [loan-delinquency-workflow.md](./loan-delinquency-workflow.md)). UI polish and contribution arrears alignment completed in follow-up work.

### Prompt #32 — loan page insights

**Status:** Completed for listed tenant/member loan pages (see **Loan insights widgets** above). Polling removed by design after bug fix.

---

## Remaining (broader backlog — `prompts.txt`)

The following are **outside** the delinquency/loan-insights session scope but appear in [prompts.txt](./prompts.txt) as product goals. They are **not** marked done here unless already present elsewhere in the app.

| Area | Prompt refs (summary) |
|------|------------------------|
| Fund flow fundamentals | Items 1–9 — bank import, master cash/fund mirroring, contribution cycles, loan disbursement accounting |
| Member posted funds | Item 1 — approve/reject, uncleared bank, attachments, notifications |
| Bank import templates | Items 2–4 — templates, encoding, amount structure, duplicate rules, red/green amounts |
| Global UI rules | Items 5–11 — bilingual strings, theming (member/admin/central), table footers, mobile-first, striped tables, debit styling |
| Transactions UX | Items 12, 19–28 — transaction modal, reverse/split/refund, datetime, group by, account widgets |
| Public / membership | Item 14 — public page settings, enrollment workflow |
| Table actions | Item 15 — grouped row actions, bulk actions (partially enforced via workspace rules for loans cluster) |
| Applications-style widgets | Items 16–17, 27–28 — other pages may still need parity |
| Legacy migration | Items 29–31 — full legacy contributions, statements, member management parity |
| Loan UX (legacy parity) | Item 30 — stepper, stages, partial settlement, calculator, etc. (much may already exist; needs audit vs legacy) |

Use [prompts.txt](./prompts.txt) as the source of truth for full wording and priorities.

---

## Quick verification commands

```bash
# Delinquency tests
php artisan test --compact tests/Feature/Tenant/LoanDelinquencyServiceTest.php

# Loan insights tests
php artisan test --compact tests/Feature/Tenant/LoanInsightsServiceTest.php

# Scheduled commands (tenant context)
php artisan loans:check-defaults
php artisan delinquency:send-digest
```

---

## Summary

| Category | Status |
|----------|--------|
| Loan/fund tier table UX | Done |
| Loan tables column manager | Done |
| Loan insights (tenant + member) | Done |
| Insights polling bug | Fixed |
| Deposits rename | Done |
| Delinquency backend + UI + follow-ups | Done |
| Contribution arrears alignment | Done |
| Delinquency docs | Done |
| Delinquency optional (email, filters) | Not done |
| Broader `prompts.txt` backlog | Not done (separate initiatives) |
