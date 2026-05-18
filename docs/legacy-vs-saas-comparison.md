# Legacy FundFlow vs FundFlow SaaS — Comprehensive Comparison

**Last updated:** 2026-05-18  
**Audience:** Product owners, fund operators, and engineering planning parity work.

---

## 1. Purpose and scope

This document compares the **legacy FundFlow** application (single-tenant mutual-fund operations tool) with **FundFlow SaaS** (this repository: multi-tenant Laravel 12 + Filament v5).

### Sources used

| Source | Role |
|--------|------|
| Legacy behavior (inferred) | `docs/prompts.txt`, `docs/loan-ux-and-workflow-recommendations.md`, `docs/fund-flow-implementation.md`, `docs/initial-design.md` |
| SaaS implementation (verified) | `app/Services/`, `app/Filament/`, `tests/Feature/`, `routes/console.php` |
| Session status | `docs/implementation-status.md`, `docs/member-portal.md`, `docs/loan-delinquency-workflow.md` |

**Important:** The legacy application source tree is **not** in this repository. Gaps marked “legacy (documented)” come from product prompts and comparison docs, not line-by-line diff of old PHP. Where SaaS code exists, status is based on current implementation and tests.

### Status legend

| Status | Meaning |
|--------|---------|
| **Parity** | Equivalent capability for typical fund operations |
| **Partial** | Core behavior exists; UX, coverage, or edge cases differ |
| **New** | SaaS-only (not in legacy scope) |
| **Missing** | Documented legacy expectation not implemented or only stubbed |
| **Deferred** | Intentionally simplified vs legacy (documented recommendation) |

---

## 2. Executive summary

### What SaaS does well

- **Correct multi-leg accounting** for bank import → mirror → post → contribution → loan disburse/payout/repay, with strong automated tests.
- **Multi-tenancy** (database-per-tenant, central billing, plans/subscriptions).
- **Modern admin UX**: insights widgets on many resources, delinquency workspace, loan cluster, membership wizard, tenant dashboard.
- **Member portal** at `/member` with deposits, loans, calculator, guaranteed loans, messages, statements, early payoff, and open-period repayment.
- **Operational automation**: scheduled contribution apply/notify, loan due notifications, auto-repayment job, delinquency checks, statement generation, SMS/WhatsApp hooks.

### Where legacy still “feels” ahead

- **Daily operator muscle memory**: loan queue as the primary home, installment grid as the source of truth, tier buckets, and dense legacy repayment rows wired to cron.
- **Visual richness**: legacy dashboards/widgets were tuned for heavy gradient/analytics presentation (`prompts.txt` #34); SaaS dashboards are functional but less visually ambitious.
- **Reporting**: neither project has a full analytics suite; legacy may have had more ad-hoc exports/operator views not yet recreated.
- **Uniform table polish**: prompts asked for count/sum/average footers, clickable stats everywhere, column manager on all tables, and insights on every page — **partially** applied in SaaS.

### Bottom-line assessment

SaaS has **caught up or exceeded legacy** on fund-flow correctness, loans (including tiers, partial disbursement, delinquency, guarantors), deposits, membership, and member self-service. Remaining work is mostly **UX parity**, **reporting**, **legacy data coexistence clarity** (installments vs “legacy repayments”), and **finishing global UI rules** from `prompts.txt` — not rebuilding core accounting.

---

## 3. Platform and architecture differences

| Topic | Legacy FundFlow | FundFlow SaaS |
|-------|-----------------|---------------|
| Deployment | Single fund / single database | Multi-tenant; isolated DB per fund |
| Admin URL | (varies) | Tenant panel: `/admin` on tenant domain |
| Member URL | Often `/portal` in early docs | **`/member`** (`MemberPanelProvider`) |
| Central operator | N/A | `/admin` on central domain (tenants, plans, invoices) |
| Auth | Fund users | `tenant` guard; members cannot access admin panel |
| Branding | Legacy asset pack | Shared `FundflowBrand`; per-tenant public settings (logo, fund name EN/AR) |
| Permissions | Fund-local roles | Filament Shield on central; tenant admin flag |
| Household login | Simpler or ad-hoc | **New:** profile picker, internal emails, separated dependents (`docs/household-members.md`) |
| PWA / offline | Unknown | Tenant shell: manifest, offline page (public routes) |
| Storage URLs | Old paths | `tenant.storage-legacy` redirect for old file URLs |

**Recommendation:** Update `docs/initial-design.md` (`/portal` → `/member`, `/manage` → `/admin`) to avoid onboarding confusion.

---

## 4. Fund flow and accounting (core rules)

Reference: `docs/prompts.txt` items 1–9, `docs/fund-flow-implementation.md`.

| Rule / flow | Legacy | SaaS | Status | Notes & recommendation |
|-------------|--------|------|--------|-------------------------|
| Bank CSV import | Yes | `BankImportService`, templates in Settings → **Bank Templates** | **Parity** | Encoding, header row, one vs split amount columns, extra column keys, duplicate rules — implemented per prompts #2–4. |
| Duplicate detection | Template-driven | Hash + template duplicate rules | **Parity** | Verify production templates match each bank’s CSV. |
| Mirror import → master cash | Yes | `FundFlowService::mirrorToCash()` | **Parity** | Status: `imported` → `mirrored`. |
| Post to member cash | Yes | `FundFlowService::postToMember()` | **Parity** | Member suggestion service exists; confirm match UX on bank tables. |
| Contribution cycle day | Configurable | Settings → Contributions (`cycle_start_day`) | **Parity** | Default 6th. |
| One contribution/repayment per member per cycle | Yes | `ContributionService` | **Parity** | Enforced in service layer. |
| Contribution posting (cash → fund → master fund) | Yes | `ContributionService::postContribution()` | **Parity** | Tested. |
| Loan disburse (fund + member fund debit, member cash credit) | Yes | `LoanLifecycleService` / `LoanLedgerService` | **Parity** | “Adapted from legacy” in code comments. |
| Loan payout (member cash + master cash debit) | Yes | Payout action + ledger | **Parity** | UI labels still say “Disburse/Payout” in places — see loan UX. |
| Loan repayment via cycle | Yes | `recordRepayment`, scheduled `loans:apply-repayments` | **Parity** | Plus member open-period and early settle. |
| Master fund / master cash balance rules | Yes | `AccountingService` | **Parity** | |
| Member fund may go negative | Yes | Yes | **Parity** | |
| Single active loan | Yes | Enforced | **Parity** | |
| Master invest / expense / reserve accounts | Legacy ops | Master account types + header actions | **Partial** | Exists; operator training may differ from legacy screens. |

---

## 5. Banking and deposits

### 5.1 Bank statements and transactions

| Feature | Legacy | SaaS | Status | Recommendation |
|---------|--------|------|--------|----------------|
| Import with template picker | Yes | Bank accounts → import header action | **Parity** | |
| Red debits / green credits | Yes | `LedgerAmountColumn` + `MoneyDisplay` | **Parity** | Amounts show **absolute value**; sign implied by color (prompt #24). |
| Transaction detail modal | Yes | `ViewBankTransactionAction`, `ViewAccountTransactionAction` | **Partial** | Present on bank + account RMs; audit **every** transaction table. |
| Click row → edit modal | Prompt #20 | `EditAccountTransactionAction` on account tables; bank uses view modal | **Partial** | Align bank row click with account behavior if operators expect edit-on-click everywhere. |
| Reverse / split / delete | Yes | `AccountingService` + Filament actions | **Parity** | Tested for tenant account tables. |
| Manual credit/debit datetime | Prompt #19 | Supported in manual adjustment actions | **Parity** | |
| Refund on member cash | Legacy header action | `AccountTransactionManualAdjustmentHeaderActions` refund | **Parity** | |
| Bulk delete transactions | Prompt #22 | Bulk delete on transaction toolbars | **Parity** | |
| Group by on bank txns | Yes | `TableGrouping::bankTransactions()` | **Parity** | |
| Ignore imported rows | Yes | Ignore action | **Parity** | |
| Clear member deposit vs import | Uncleared until matched | `FundPostingService::clearTransaction` | **Parity** | |

### 5.2 Member deposits (“posted funds”)

| Feature | Legacy | SaaS | Status | Recommendation |
|---------|--------|------|--------|----------------|
| Member submits deposit request | Yes | `FundPostingService::submit`, member **Deposits** resource | **Parity** | Renamed from “Posted Funds” / “Fund postings”. |
| Fields: date, amount, reference, attachment, notes | Yes | Create form + storage | **Parity** | |
| Admin approve/reject | Yes | Fund postings (Deposits) list actions | **Parity** | |
| Admin notification on new request | Yes | `NewFundPostingNotification` | **Parity** | |
| Member notification on accept/reject | Yes | Accepted/rejected notifications | **Parity** | |
| Credit master cash → member cash on accept | Yes | Service flow | **Parity** | |
| Uncleared until bank match | Yes | Cleared via import matching | **Parity** | Document operator SOP in fund onboarding. |
| Deposits page insights widget | Legacy reference (#35) | `FundPostingInsightsWidget` (tenant + member list) | **Parity** | |
| Member bulk deposit posting | Unknown | Single create per request | **Partial** | Add bulk submit only if legacy had true bulk upload. |

---

## 6. Contributions and cycles

| Feature | Legacy | SaaS | Status | Recommendation |
|---------|--------|------|--------|----------------|
| Contribution list / create | Yes | `ContributionResource` | **Parity** | |
| Contribution cycle UI | Yes | `ContributionCyclePage` (hidden nav; linked from contributions/dashboard) | **Partial** | Consider nav entry or dashboard CTA if operators cannot find it. |
| Bulk apply for open period | Yes | Cycle page bulk actions | **Parity** | |
| Scheduled notify + apply | Yes | `contributions:notify`, `contributions:apply` | **Parity** | Confirm cron on production. |
| Contribution insights widget | Yes | `ContributionInsightsWidget` | **Parity** | |
| Late contribution fees | Legacy | `LateFeeService` + delinquency arrears tab | **Parity** | |
| Auto-allocate contribution to loan | Legacy flag | Setting `auto_allocate_loan_repayment` + `ContributionService` hook | **Parity** | Off by default; enable per fund policy. |
| Dependent contributions via parent | Yes | Household + parent member | **Partial** | SaaS household model is **richer** but different; train admins on `docs/household-members.md`. |
| Full legacy “contributions flow” UX | Rich | Functional | **Partial** | Prompt #31 — audit operator steps vs legacy checklist (export, filters, shortcuts). |

---

## 7. Loans

### 7.1 Configuration and structure

| Feature | Legacy | SaaS | Status | Recommendation |
|---------|--------|------|--------|----------------|
| Loan settings (eligibility, terms, grace) | Many keys | Settings → Loans (`LoanSettings`) | **Partial** | Fewer knobs than legacy; add only if bylaws require. |
| Loan tiers | Yes | `LoanTierResource` | **Parity** | |
| Fund tiers + declared pool | Yes | `FundTierResource` + partial disburse when over pool | **Parity** | |
| Queue ordering / resequence | Yes | `LoanQueueOrderingService`, bulk/row resequence actions | **Parity** | |
| Emergency loans | Yes | `is_emergency`, emergency fund tier | **Parity** | |
| Loan queue page | Primary workspace | Loans cluster → Queue + tabs | **Parity** | Old `LoanQueue` page is a redirect stub. |
| CSV loan import | Legacy | Not found | **Missing** | Low priority unless migration needs it; prefer apply + queue. |

### 7.2 Lifecycle and accounting

| Feature | Legacy | SaaS | Status | Recommendation |
|---------|--------|------|--------|----------------|
| Apply (member) | Yes | Wizard: Amount → Purpose → Witnesses → Review | **Parity** | Guarantor when amount > fund balance (setting). |
| Apply (admin) | Yes | Create / actions | **Parity** | |
| Approve / reject / cancel | Yes | `LoanFilamentActions` | **Parity** | |
| Partial disbursement | Yes | `disbursePartial`, disbursements RM, notifications | **Parity** | |
| Full disburse + payout steps | Yes | Disburse + payout actions | **Parity** | Rename UI to “Allocate to ledger” / “Send to bank” per `loan-ux-and-workflow-recommendations.md`. |
| Installment schedule | Core in legacy | `LoanInstallment` model + installments RM | **Partial** | Generated/maintained; member schedule **display** may be thinner than legacy grid. |
| Legacy repayment rows | Primary in old app | **Repayments** RM titled “Legacy repayments” | **Partial** | Keep for historical data; document which report is authoritative (installments vs legacy rows). |
| Record repayment (admin) | Yes | Actions on loan view | **Parity** | |
| Early payoff | Yes | `LoanEarlySettlementService`, member + admin actions | **Parity** | |
| Open-period repayment (member) | Yes | Pay this period | **Parity** | |
| Partial settlement (installment-level) | Legacy | Unclear as dedicated UI | **Partial** | Map legacy “partial settlement” to installment payments; add explicit action if operators need it. |
| Auto repayment from cash (scheduled) | Yes | `loans:apply-repayments` | **Parity** | |
| Single active loan | Yes | Enforced | **Parity** | |

### 7.3 Guarantors, delinquency, fees

| Feature | Legacy | SaaS | Status | Recommendation |
|---------|--------|------|--------|----------------|
| Guarantor on apply | Yes | Required above fund balance (configurable) | **Parity** | |
| Guaranteed loans (member view) | Yes | `MyGuaranteedLoanResource` | **Parity** | |
| Guarantor notification on apply | Yes | `GuarantorLoanApplicationNotification` | **Parity** | |
| Late fees on installments | Yes | `LateFeeService`, overdue marking | **Parity** | |
| Delinquency tracking | Yes | `LoanDelinquencyService`, daily job | **Parity** | |
| Transfer liability to guarantor | Yes | Admin actions + `guarantor_liability_transferred_at` | **Parity** | |
| Restore borrower liability | Yes | Admin action | **Parity** | |
| Delinquency workspace (3 tabs) | Ad-hoc in legacy | Loans → Delinquency | **New / better** | Overdue installments, contribution arrears, guarantor exposure. |
| Admin delinquency digest | Email/digest | `delinquency:send-digest` + mail channel | **Parity** | |
| Member arrears banner | Unknown | `MemberArrearsAlert` on member dashboard | **New** | |

### 7.4 Loan UX and notifications

| Feature | Legacy | SaaS | Status | Recommendation |
|---------|--------|------|--------|----------------|
| Horizontal loan stepper on view | Requested (#30) | In `LoanViewInsights` via `LoanUserFacingStage::stepperFor()` | **Partial** | Stepper in **widget**, not always primary page chrome; promote to hero/stepper section on view. |
| User-facing stage labels | Mixed | `LoanUserFacingStage` enum | **Partial** | Enforce on list badges, emails, and member portal consistently. |
| Loan insights (portfolio, queue, tiers, detail) | Rich widgets | `LoanInsightsService` + widgets | **Parity** | Polling removed after bug — do not re-enable without stable context. |
| Member loan calculator | Yes | `LoanCalculator` page | **Parity** | |
| State-change notifications | Many | 15+ notification classes (submitted, approved, rejected, disbursed, partial, repayment, default, guarantor, early settle, etc.) | **Parity** | Add SMS/WhatsApp only when Twilio configured. |
| Queue tabs (needs decision / ready / awaiting payout) | Implicit | Queue list + filters | **Partial** | Add explicit tabs per Phase A in loan UX doc. |
| Loan view as “command center” | Yes | View + header actions + RMs | **Partial** | Reduce reliance on crowded action dropdowns. |

---

## 8. Members and membership

| Feature | Legacy | SaaS | Status | Recommendation |
|---------|--------|------|--------|----------------|
| Member CRUD | Yes | `MemberResource` (edit-centric) | **Partial** | No dedicated **view** page — insights on edit instead. |
| Member insights on profile | Yes | `MemberDetailInsightsWidget` | **Parity** | |
| Accounts RM (cash / fund / loans tabs) | Yes | Relation manager + tabbed transactions | **Parity** | Prompt #13 — three transaction tabs. |
| Dependents / household | Yes | `DependentsRelationManager`, household services | **New / richer** | See household doc; legacy data may need migration cleanup. |
| Admin impersonation of member | Unknown | Impersonation service + topbar hook | **New** | |
| Membership applications | Yes | Resource + approval service | **Parity** | |
| Public multi-step enrollment | Landing section | `/membership`, `/apply` wizard + status page | **Parity** | Prompt #14 — dedicated membership routes. |
| Public settings (cap, fees, documents) | Yes | Settings → Public page | **Parity** | |
| Application import (CSV/Excel) | Yes | `MembershipApplicationImportService` | **Parity** | |
| Application insights + stepper | Yes | `MembershipApplicationInsightsWidget` | **Parity** | |
| Member number rules | Yes | Settings → General | **Parity** | |
| Messages admin ↔ member | Unknown | `MessagesRelationManager` + `MyMessageResource` | **New** | |
| Full legacy member management parity | Yes | — | **Partial** | Prompt #31 — field-level audit (documents, custom fields, reports). |

---

## 9. Member portal

| Area | Legacy (typical) | SaaS (`/member`) | Status | Recommendation |
|------|----------------|-----------------|--------|----------------|
| Dashboard / greeting | Profile card | `portal-greeting-hero` + insights KPIs | **Partial** | Functional; less gradient-heavy than legacy prompt #34 vision. |
| My accounts | Read-only | `MyAccountResource` | **Parity** | |
| Contributions | View | `MyContributionResource` | **Parity** | |
| Deposits | Submit + track | `MyFundPostingResource` | **Parity** | |
| Loans + apply | Yes | `MyLoanResource`, `ApplyForLoan` | **Parity** | |
| Guaranteed loans | Yes | `MyGuaranteedLoanResource` | **Parity** | |
| Calculator | Yes | `LoanCalculator` | **Parity** | |
| Statements | PDF/list | `MyStatementResource` + route | **Parity** | |
| Messages | Unknown | `MyMessageResource` | **New** | |
| Profile edit | Yes | User menu profile pages | **Parity** | |
| Fund name in header | — | Topbar hook (`topbar-fund-name`) | **New** | |
| SMS/WhatsApp alerts | Unknown | Twilio settings + channels | **Partial** | Requires production credentials. |
| Cannot access admin | Yes | `canAccessPanel()` | **Parity** | |

---

## 10. Monthly statements

| Feature | Legacy | SaaS | Status | Recommendation |
|---------|--------|------|--------|----------------|
| Generate statements | Yes | `MonthlyStatementService`, `statements:generate` | **Parity** | |
| Member PDF/view | Yes | Member resource + download route | **Parity** | |
| Notify members | Yes | `MonthlyStatementNotification` | **Parity** | |
| Admin statements list | Yes | `MonthlyStatementResource` | **Parity** | |
| Statement insights widget | Legacy | `MonthlyStatementInsightsWidget` | **Parity** | |
| Legacy statement layout parity | Exact PDF | May differ | **Partial** | Compare PDF template with legacy if members complain. |

---

## 11. Notifications and messaging

| Channel | Legacy | SaaS | Status |
|---------|--------|------|--------|
| Database (in-app) | Yes | Tenant + member panels | **Parity** |
| Mail | Yes | Used for digest, statements, etc. | **Parity** |
| SMS | Likely | Twilio `SmsChannel` | **Partial** (config-dependent) |
| WhatsApp | Likely | Twilio `WhatsAppChannel` | **Partial** (config-dependent) |

**SaaS notification types (tenant):** new fund posting, fund posting accepted/rejected, contribution due, monthly statement, loan submitted/approved/rejected/disbursed/partial/repayment/due/default/guarantor/early settled/settled, delinquency digest, new loan application, guarantor application.

**Gap:** No automated test matrix for every notification; production Twilio and cron must be verified.

---

## 12. Central SaaS platform (no legacy equivalent)

| Feature | Status |
|---------|--------|
| Tenant provisioning / deletion | **New** |
| Plans, subscriptions, invoices | **New** |
| Purchase plan flow | **New** |
| Central admin (Rose theme) | **New** |
| Filament Shield RBAC | **New** |

---

## 13. Reporting and analytics

| Feature | Legacy | SaaS | Status | Recommendation |
|---------|--------|------|--------|----------------|
| Dedicated reports module | Likely partial | **Not found** | **Missing** | Define MVP: trial balance, member statement export, loan portfolio CSV. |
| Dashboard charts/gauges | Rich | `TenantDashboardService` (gauges, charts, quick actions) | **Partial** | Prompt #34 — more animation/gradient if desired. |
| Sparkline on member dashboard | — | Contribution sparkline in insights | **Partial** | |
| Export per table | Unknown | Filament export on some resources | **Partial** | Inventory per resource. |

---

## 14. Global UI / UX rules (`prompts.txt` #5–28, #34)

| Rule | Requested | SaaS | Status | Recommendation |
|------|-----------|------|--------|----------------|
| Bilingual UI | All literals | `lang/ar.json`, `translateLabel()`, `__()` | **Partial** | Ongoing; audit new strings. |
| Panel theming (green/blue/red) | Member / admin / central | Emerald / Sky / Rose | **Parity** | |
| Table footer count + sum + average | All three | **Sum only** on qualifying columns (`TableSummaryFooter`) | **Partial** | Add Count/Average summarizers where prompts require. |
| Mobile-first tables | Yes | Global column defaults in `AppServiceProvider` | **Parity** | |
| Striped tables | Yes | Global `striped()` | **Parity** | |
| Header label capitalize first letter | Yes | `CapitalizesTableColumnHeaderLabel` | **Parity** | |
| Debits red, no minus sign | Yes | `MoneyDisplay` | **Parity** | |
| Transaction click → details | Modal | View actions on bank/account | **Partial** | Extend to any remaining tables. |
| Row click → edit transaction | Yes | Edit action on account transactions | **Partial** | |
| Grouped row actions | Yes | Workspace rule + `TableRecordActionGroups` | **Partial** | Audit tables not yet compliant. |
| Bulk actions mirror row actions | Yes | Many tables | **Partial** | Per-resource audit. |
| Group by | Appropriate tables | `TableGrouping` presets | **Partial** | Not on every table. |
| Column manager | All tables | Loans cluster + some member loan tables | **Partial** | Roll out or document exceptions. |
| Clickable stat cards | Yes | Tenant dashboard quick actions; `FundOverview` URLs | **Partial** | Extend to all stat widgets. |
| Insights widgets on all major pages | Yes | Many, not all | **Partial** | Accounts OK; check remaining list pages. |
| Compact combined stats | Yes | Insights pattern | **Partial** | |
| Beautiful gradients everywhere | Yes | Reverted on member dashboard; subtle on some cards | **Partial** | Product decision: subtle (tenant-style) vs legacy vivid. |
| Advanced tenant dashboard (#34) | Yes | `TenantDashboardWidget` | **Partial** | Functional; not as visually dense as prompt vision. |

---

## 15. Scheduled automation

| Job | Schedule | Purpose |
|-----|----------|---------|
| `contributions:notify` | Monthly 1st 09:00 | Contribution due reminders |
| `contributions:apply` | Monthly 5th 09:00 | Apply contributions |
| `statements:generate --notify` | Monthly 3rd 08:00 | Statements |
| `loans:send-due-notifications` | Monthly 1st 08:00 | Repayment due |
| `loans:apply-repayments` | Monthly 6th 06:00 | Auto loan repayment from cash |
| `loans:check-defaults` | Daily 07:00 | Delinquency |
| `delinquency:send-digest` | Daily 07:30 | Admin digest |

**Recommendation:** Document tenant timezone and verify cron in staging/production (called out in `implementation-status.md`).

---

## 16. Data model and technical coexistence

| Topic | Legacy | SaaS | Impact |
|-------|--------|------|--------|
| Installments vs repayments | Single model | `loan_installments` + `loan_repayments` (legacy rows) | Operators may see duplicate concepts — **document in admin help**. |
| Loan schema migration | — | `upgrade_loans_to_legacy_full_schema` migration | Migration path exists for legacy-shaped data. |
| `fund_name` setting | Single field | Split `fund_name_en` / `fund_name_ar` with read migration | **Parity** with upgrade path. |
| Public storage paths | Old URLs | Legacy redirect route | **Parity** |

---

## 17. Small but notable differences

| Item | Detail |
|------|--------|
| Navigation label **Deposits** | Replaces “Posted Funds” / “Fund postings” — same entity, new label. |
| Tenant admin path | `/admin` (not `/manage` from early design doc). |
| Member path | `/member` (not `/portal`). |
| Repayments RM title | Explicitly **“Legacy repayments”** to avoid confusion with installment schedule. |
| Loan insights polling | Removed to fix context bug; optional re-enable only with stable route context. |
| `implementation-status.md` summary table | Stale on delinquency email/filter — code has mail + member filter. |
| No `TODO` in `app/` | Backlog lives in docs, not code markers. |

---

## 18. Overall assessment

### Maturity by domain

| Domain | Maturity vs legacy | Comment |
|--------|-------------------|---------|
| Core accounting & fund flow | **High** | SaaS is the source of truth; well tested. |
| Banking & imports | **High** | Templates match modern requirements. |
| Deposits | **High** | End-to-end with notifications. |
| Contributions | **Medium–High** | Cycle page discoverability is the main UX gap. |
| Loans | **High** | Feature-complete; UX storytelling still catching up. |
| Delinquency | **High** | Exceeds typical legacy with dedicated workspace. |
| Member portal | **Medium–High** | Broad coverage; dashboard polish optional. |
| Membership / public | **High** | Wizard + settings. |
| Reporting | **Low** | Both weak; SaaS has not closed gap. |
| Global table/UI rules | **Medium** | Foundations global; prompts #7, #15, #16–17, #27–28 uneven. |

### Strategic recommendation

1. **Do not rebuild legacy accounting** — invest in clarity (labels, stepper, queue tabs, help text for installments vs legacy repayments).
2. **Treat `prompts.txt` as a backlog**, not a failure list — many items are done; use this document for what remains.
3. **Prioritize operator efficiency** over visual effects unless stakeholders reaffirm prompt #34 gradient scope.
4. **Add a minimal reports/export milestone** if legacy operators relied on spreadsheets.
5. **Production hardening**: cron, Twilio, `view:clear` on deploy, tenant migration for household/loan schema.

---

## 19. Recommended accommodation roadmap

### Phase 1 — Clarity and operator UX (2–4 weeks)

- [ ] Loan view: primary **stepper** + single “next action” (not only inside insights widget).
- [ ] Loan queue: explicit **tabs** (pending / approved / awaiting payout / repaying).
- [ ] Rename disburse/payout actions to **Allocate to ledger** / **Send to bank** (EN + AR).
- [ ] Admin doc: **installments vs legacy repayments** and which drives balance.
- [ ] Contribution cycle: clearer **navigation** from dashboard and contributions list.
- [ ] Update **initial-design.md** paths (`/member`, `/admin`).

### Phase 2 — Global UI parity (2–3 weeks)

- [ ] Table footers: add **Count** and **Average** where `prompts.txt` #7 requires (keep Sum).
- [ ] Audit **row action groups + bulk** on all tenant/member tables.
- [ ] Audit **transaction modals** on every transaction grid.
- [ ] Optional **column manager** on high-traffic non-loan tables (members, contributions, deposits).

### Phase 3 — Reporting (4+ weeks)

- [ ] Define report pack with fund treasurer: portfolio, arrears, cash reconciliation, member activity.
- [ ] Implement exports or Filament report pages; reuse existing services (`LoanInsightsService`, `LoanDelinquencyService`, etc.).

### Phase 4 — Optional legacy depth (only if bylaws require)

- [ ] CSV loan import.
- [ ] Deeper partial-settlement UI per installment.
- [ ] Extra loan settings keys (grace variants, tier caps) from legacy.
- [ ] Richer dashboard animations/gradients (product decision).

### Phase 5 — Production and migration

- [ ] Cron checklist per tenant.
- [ ] Twilio smoke test for SMS/WhatsApp.
- [ ] Legacy data migration runbook (`tenants:migrate`, household backfill per `household-members.md`).

---

## 20. Appendix A — SaaS feature inventory (quick reference)

**Tenant panel resources:** Members, Applications, Contributions, Deposits, Monthly statements, Loans cluster (loans, queue, tiers, delinquency), Bank accounts, Master accounts, Member accounts.

**Member panel:** Dashboard, My accounts, Contributions, Deposits, Loans, Guaranteed loans, Statements, Messages, Calculator, Profile.

**Central panel:** Tenants, Plans, Subscriptions, Invoices, Purchase plan.

**Key services:** `AccountingService`, `FundFlowService`, `BankImportService`, `FundPostingService`, `ContributionService`, `ContributionCycleService`, `LoanService` + `App\Services\Loans\*`, `MonthlyStatementService`, `MembershipEnrollmentService`, `MemberPortalInsightsService`, `TenantDashboardService`, `LoanDelinquencyService`, `TwilioMessagingService`.

---

## 21. Appendix B — Related documentation

| Document | Use |
|----------|-----|
| [prompts.txt](./prompts.txt) | Original product requirements (legacy parity + SaaS rules) |
| [implementation-status.md](./implementation-status.md) | Recent loan/delinquency/dashboard completion status |
| [loan-ux-and-workflow-recommendations.md](./loan-ux-and-workflow-recommendations.md) | Loan UX gap analysis |
| [loan-delinquency-workflow.md](./loan-delinquency-workflow.md) | Delinquency technical design |
| [member-portal.md](./member-portal.md) | Member portal architecture |
| [fund-flow-implementation.md](./fund-flow-implementation.md) | Accounting lifecycle |
| [household-members.md](./household-members.md) | Household vs legacy dependents |

---

*This document should be updated when major legacy-parity work ships or when legacy operators provide a concrete feature checklist from the old application.*
