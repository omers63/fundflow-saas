# Implementation status

Last updated after **full specification completion pass** (workflow docs + `prompts.txt` fundamentals, optional compliance depth, and global UI rules).

**Related docs**

- [loan-delinquency-workflow.md](./loan-delinquency-workflow.md) — delinquency, late fees, guarantor liability, scheduled jobs
- [production-runbook.md](./production-runbook.md) — deploy, cron, migration onboarding paths
- [prompts.txt](./prompts.txt) — product backlog source of truth
- [legacy-vs-saas-comparison.md](./legacy-vs-saas-comparison.md) — parity matrix vs documented legacy
- [fund_management_system_requirements.md](./fund_management_system_requirements.md), [collection_cycle_workflow.md](./collection_cycle_workflow.md), [loan_lifecycle_workflows.md](./loan_lifecycle_workflows.md) — workflow specs

---

## Executive summary

| Area | Status |
|------|--------|
| Fund flow fundamentals (`prompts.txt` 1–9) | **Parity** — bank import → mirror → post → cycles → loan disburse/payout |
| Compliance (recon, migration, EMI late fees, jobs) | **Done** (foundation + depth pass) |
| Loans (lifecycle, delinquency, insights, stepper) | **Done** |
| Member portal & deposits | **Done** |
| Global table rules (striped, selectable, grouped actions, footers) | **Done** (sum on qualifying columns; AGENTS.md global default) |
| Theming (green / blue / red panels) | **Done** (gradient panel backgrounds in theme CSS) |
| Optional visual ambition (animations, clickable stats everywhere) | **Partial** — functional links on dashboard/widgets; not every stat card globally |

---

## Completed — specification pass (2026-05-21)

### Compliance & operations

| Item | Notes |
|------|--------|
| **System → Jobs** | Catalog, run history, manual run, `BatchPostingGate` |
| **Reconciliation** | Nightly batch, dedupe, auto-resolve, EMI/loan/bank/migration domains, halt gate |
| **Reconciliation suspense** | `ReconciliationSuspenseService` — suspense account, rounding adjustments, timing defer (24h) / escalate (48h) |
| **Migration** | Stubs, settlement (lump / instalment / OB offset), opening balances (`MIGRATION_OPENING`), stubs RM, **`MigrationWorkflowPage`** (Fund Management nav), member form migration section |
| **EMI late fees** | `LoanInstallmentLateFeeService` + `contributions:apply-late-fees` |
| **Loan `partially_disbursed`** | Status + lifecycle stage + UI labels + disburse/payout paths |
| **Loan display labels** | Pending admin review, Repaid (`completed` / `early_settled`) |
| **Collection cycle nav** | `ContributionCyclePage` registered under **Fund Management** |
| **Collection summary CSV** | Contribution cycle page export |

### UI / `prompts.txt` alignment

| Prompt | Status |
|--------|--------|
| 1–4 Bank / deposits / templates | **Done** (prior work + settings) |
| 5 Bilingual | **Done** (`translateLabel`, `lang/ar.json`, `__()` for non-Filament literals) |
| 6 Theming | **Done** — member (green), tenant (blue), central (red) theme CSS |
| 7 Table footers | **Partial** — sum on aggregatable columns only (`TableSummaryFooter`; prompts #7 count/average not applied) |
| 8–11 Mobile, headers, striped, debit styling | **Done** (global `AppServiceProvider` + ledger columns) |
| 12, 19–23 Transactions | **Done** — modal view, edit on row click, reverse/split/refund/delete + bulk delete |
| 13 Member ledger tabs | **Done** — `MemberTransactionsTabsRelationManager` (Cash / Fund / Loans) |
| 14 Public / membership | **Done** — `PublicPageSettings`, enrollment wizard, fees, caps |
| 15 Grouped row + bulk actions | **Done** (workspace rule + global selectable tables) |
| 16–17, 27–28 Insights widgets | **Done** on dashboards, accounts, loans, applications, members |
| 29–33 Loans / delinquency / dashboard | **Done** (see delinquency doc + loan insights) |
| 30 Loan stepper | **Done** — `LoanUserFacingStage` + `<x-loan-pipeline-stepper>` on loan view insights |
| 31 Contributions / statements / members | **Done** (SaaS resources; UX differs from legacy) |
| 34 Tenant dashboard | **Done** — `TenantDashboardService` + custom dashboard |
| 36 My Deposits | **Done** (member nav); legacy settings consolidated into tenant **Settings** page |

---

## Completed — prior sessions (unchanged)

Loan/fund tier column manager, loan insights (tenant + member), deposits rename, delinquency backend + UI + digest email, contribution arrears alignment, subscription fees on membership approval.

**Tests (representative):**

- `ComplianceLayerTest.php` (9) — migration, audit, recon, opening balances, loan labels
- `LoanDelinquencyServiceTest.php`, `TenantDashboardServiceTest.php`, `TableSummaryFooterTest.php`, etc.

---

## Spec closure pass (workflow docs — 2026-05-21)

| Priority | Status | Implementation |
|----------|--------|----------------|
| Reconciliation depth | **Expanded** | `reconcileLateFees()`, `reconcileMemberInvariants()`, `PENDING_PAST_WINDOW_CLOSE`, `CASH_DEPOSIT_UNBANKED`, `MIGRATION_LATE_FEE_APPLIED`, auto-resolve paths |
| Master invariant + backdated due | **Done** | `MasterAccountInvariantService` expects `member fund sum + Σ(BACKDATED_DUE open)` |
| EMI collection parity | **Done** | `LoanInstallmentCollectionCycleService`, `loans:close-emi-window` (scheduled 6th 00:45) |
| Loan `transferred` status | **Done** | Guarantor transfer sets `transferred`; repayment/collection scopes include it |
| Cumulative late fees | **Done** | Contributions + EMI post tier **increment**; replacement reverses prior fee |
| Configurable max active loans | **Done** | `LoanSettings::maxActiveLoans()` enforced in `LoanEligibilityService` |

**Tests:** `tests/Feature/Tenant/SpecClosureTest.php` (6), `ComplianceLayerTest.php` (9).

### Workflow docs — reconciliation & migration (2026-05-21 closure pass)

| Area | Status |
|------|--------|
| Admin resolution UX | **Done** — `ReconciliationResolutionService` + Filament actions: view, assign, reclassify, escalate, write-off, accept override, retry auto-resolve |
| Recon matrix expansion | **Expanded** — duplicate debits, EMI overdue clock, backdated due, partial clearance monitoring, fee income drift, cash deposit unbanked, etc. |
| Auto-resolve rows | **Expanded** — exempt/migration reversal, grace EMI clear, EMI clock, fee exemption credit, partial clearance ack, window-close retry |
| `SETTLING` status | **Done** — set during partial/full collection attempts |
| `PARTIAL_CLEARANCE_GRANTED` | **Done** — `MigrationCycleService::grantPartialClearance()` + member header action |
| Real-time §5.12 | **Partial** — migration guard + late-fee exemption flags (`RECON_AUTO_FEE_EXEMPTION_REVERSAL`); no full double-entry void |

### Workflow docs — reconciliation closure (2026-05-19)

| Area | Status |
|------|--------|
| Member invariant §5.13 | **Done** — categorized fund/cash formula (contributions, migration, disbursements, EMI, deposits, fees, cash-outs) |
| Bank clearing recon | **Done** — `RECON_AMBIGUOUS_MATCH`, `RECON_UNMATCHED_BANK_LINE`, `UNMATCHED_CASH_ENTRY`, `AMOUNT_MISMATCH` in nightly batch |
| Contribution recon rows | **Expanded** — `ORPHAN_MASTER_FUND_CREDIT`, `CONTRIBUTION_AMOUNT_MISMATCH` |
| Loan disbursement recon | **Done** — `DISBURSEMENT_MEMBER_CASH_MISSING` |
| Migration opening legs | **Done** — `MIGRATION_OPENING_MISSING_LEG` |
| Real-time §5.12 | **Partial** — ineligible migration auto-reversal; `UNBALANCED_ENTRY` for bank/fund-posting paired journals |
| Filament corrections | **Done** — reverse transaction, post cash correction, resolve ambiguous bank match |

### Workflow docs — loan/EMI recon (2026-05-19)

| Area | Status |
|------|--------|
| `FUND_TIER_OVER_COMMITTED` | **Done** — nightly check on `FundTier` allocated vs exposure |
| `EMI_OVER_COLLECTION` | **Done** — threshold vs collected; auto-refund when excess ≤ one EMI |
| `GUARANTOR_BORROWER_DUPLICATE_DEBIT` | **Done** — paid-by-guarantor with borrower cash debit |
| `EMI_MISSED_SUFFICIENT_CASH` | **Done** — overdue installment while cash covers due amount |
| `EMI_COLLECTED_LEDGER_MISSING` | **Done** — paid installment without loan-account credit; auto-post repayment |
| `SCHEDULE_BEFORE_FULL_DISBURSE` | **Done** — paid installments before full disbursement |
| `CONTRIBUTION_MISSING_MASTER_CREDIT` | **Done** — auto-post master fund credit |
| Real-time member invariant | **Done** — drift check on member cash/fund postings (§5.12 tail) |
| EMI overpayment refund action | **Done** — `RECON_EMI_OVERPAYMENT_REFUND` Filament + correction service |

### Workflow docs — late fee & migration recon (2026-05-19)

| Area | Status |
|------|--------|
| `FEE_POSTED_WRONG_ACCOUNT` | **Done** — late fee credits/debits on non-fees/non-cash accounts |
| `FEE_WRONG_TIER` / `REPLACEMENT_PRIOR_TIER_NOT_REVERSED` | **Done** — tier drift + duplicate replacement debits; auto re-apply tier |
| `CONTRIBUTION_MEMBER_FUND_MISSING` | **Done** — auto-post member fund credit |
| `MIGRATION_OPENING_SUM_DRIFT` | **Done** — Σ master MIGRATION_OPENING fund vs member opening balances |
| `MIGRATION_CUTOFF_MISSING` | **Done** — opening posted without cutoff date |
| `OB_OFFSET_NEGATIVE_FUND` | **Done** — negative fund after OB offset |
| `MIGRATION_INSTALMENT_EXCESS` | **Done** — schedule total > backdated due; auto-refund when ≤ one instalment |
| `WAIVED_CYCLE_DEBITED` | **Done** — posted contribution on waived migration stub; auto-reverse |
| Post correction composer | **Done** — unified Filament action: cash, fund legs, late fee tier, EMI refund |

### Workflow docs — UI & navigation (2026-05-19)

| Area | Status |
|------|--------|
| Reconciliation Filament page | **Done** — **System → Reconciliation** (`/admin/reconciliation-exceptions`); dashboard attention card + quick action |
| Jobs & commands page | **Done** — **System → Jobs & commands** (`/admin/jobs`); removed hidden `SystemCluster` (`/admin/system/...`) |
| Fund audit log UI | **Done** — read-only **System → Audit log** |
| Loan overrides | **Done** — **System → Loan overrides** (no cluster) |
| Fund settings nav | **Done** — grouped under **System** |
| Dashboard reconciliation | **Done** — open-exception count in greeting, quick actions, attention cards |
| `MIGRATION_LEDGER_DRIFT` | **Done** — opening + lumpsum + OB offset vs opening/backdated obligation |
| Tier boundary disputes | **Done** — supervisor **Accept tier judgment** on `FEE_WRONG_TIER` / `REPLACEMENT_PRIOR_TIER_NOT_REVERSED` |

### Workflow docs — multi-leg journal composer (2026-05-19)

| Area | Status |
|------|--------|
| Custom balanced journal | **Done** — **Custom journal** Filament action on reconciliation exceptions; `AccountingService::postBalancedJournal()`; `ReconciliationCorrectionService::postCustomJournal()` |
| Composer UX | **Done** — repeater legs (account, debit/credit, amount), live balance check, defaults from exception delta |

### Workflow docs — remaining (honest)

| Area | Still open |
|------|------------|
| _(none — spec reconciliation/compliance items closed)_ | |

## Non-spec UX & ops (2026-05-19 — closed)

| Item | Status |
|------|--------|
| Clickable stat / KPI cards | **Done** — `InsightKpi` helper; tenant insight strips; Filament `StatsOverview` widgets |
| Column manager on all tables | **Done** — global `columnManager()` via `AppServiceProvider` |
| Panel visual polish | **Done** — theme gradients, `ff-stat-in` animations, KPI hover on tenant panel |
| Server cron guidance | **Done** — banner on **System → Jobs & commands** |

See [spec-coverage-audit.md](./spec-coverage-audit.md) for the three workflow `.md` cross-check.

## Remaining (optional / external)

| Item | Notes |
|------|--------|
| Git commit / PR | As requested by team |
| Legacy pixel-perfect UI | Some admin pages remain information-dense vs old PHP; functionally equivalent |

---

## Quick verification

```bash
php artisan tenants:migrate --no-interaction
php artisan test --compact tests/Feature/Tenant/ComplianceLayerTest.php
php artisan test --compact tests/Unit/TableSummaryFooterTest.php
vendor/bin/pint --dirty --format agent
```

Scheduled (tenant): `fund:nightly-reconciliation`, `contributions:init-cycle`, `contributions:close-window`, `contributions:apply-late-fees`, `loans:check-defaults`, `delinquency:send-digest`, `fund:assert-master-invariants`, `bank:auto-match`, etc. — see **System → Jobs**.
