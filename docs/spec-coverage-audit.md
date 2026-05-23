# Specification coverage audit

Cross-check of the three workflow specifications against the SaaS codebase (May 2026).

| Document | Scope |
|----------|--------|
| [fund_management_system_requirements.md](./fund_management_system_requirements.md) | Accounts, cycles, loans, migration, reconciliation, config |
| [collection_cycle_workflow.md](./collection_cycle_workflow.md) | Monthly collection phases 1–5 |
| [loan_lifecycle_workflows.md](./loan_lifecycle_workflows.md) | Eligibility, queue, tiers, disbursement, repayment, delinquency |

**Verdict:** **Full functional parity** for normative workflow requirements. Remaining gaps are operational (host cron) or cosmetic deltas vs legacy PHP UI density.

---

## fund_management_system_requirements.md

| Section | Status | Implementation |
|---------|--------|----------------|
| §1 Master / member accounts | **Done** | `Account`, `AccountingService`, master invariants |
| §1.3 Master invariant | **Done** | `MasterAccountInvariantService`, `fund:assert-master-invariants` |
| §1.4 Funding paths | **Done** | Bank import, fund postings, contributions |
| §2 Collection cycle | **Done** | `ContributionCycleService`, init/close/late-fee commands, `ContributionCyclePage` |
| §2.2 Member cycle states | **Done** | Contribution + migration stub statuses |
| §3 Loan lifecycle | **Done** | `LoanService`, queue, disbursement, EMI, early settlement |
| §3.10 Guarantor / delinquency | **Done** | `LoanDelinquencyService`, transfer, digest |
| §4 Migration | **Done** | Stubs, opening balances, settlement, partial clearance, `MigrationWorkflowPage` |
| §5 Reconciliation (all domains) | **Done** | `ReconciliationService`, Filament queue, corrections, custom journal |
| §5.11 Nightly batch | **Done** | `fund:nightly-reconciliation`, Jobs page |
| §5.12 Real-time | **Done** | `TransactionObserver`, migration guard, balanced journal check |
| §5.13 Member invariants | **Done** | `MemberInvariantService` categorized formula |
| §6 Configurable parameters | **Done** | Tenant Settings, `LoanSettings`, policy helpers |
| Appendix A journals | **Done** | Posting services + manual composer |
| Appendix B statuses | **Done** | Enums / model constants + UI labels |

---

## collection_cycle_workflow.md

| Phase | Status | Implementation |
|-------|--------|----------------|
| Phase 1 — Init (Day 1) | **Done** | `contributions:init-cycle` |
| Phase 2 — Collection window | **Done** | Auto-debit, partial debit, deposit matching |
| Phase 3 — Grace & late fees | **Done** | `contributions:close-window`, `contributions:apply-late-fees`, tier engine |
| Phase 4 — Settlement / bank | **Done** | Bank workspace, clearing, carry-forward |
| Phase 5 — Reporting / audit | **Done** | Collection summary export, `FundAuditLog`, cycle CSV |
| Member status machine | **Done** | Member + contribution statuses |
| Configuration | **Done** | `ContributionPolicySettings`, Settings page |

---

## loan_lifecycle_workflows.md

| Section | Status | Implementation |
|---------|--------|----------------|
| §1 Eligibility + overrides | **Done** | `LoanEligibilityService`, `LoanEligibilityOverrideResource` |
| §2 Submission & queue | **Done** | `LoanResource` queue, emergency flag, FIFO |
| §3 Fund tiers | **Done** | `FundTier`, allocation, over-commit recon |
| §4 Approval & disbursement | **Done** | Partial disbursement, bank payout, `partially_disbursed` |
| §5 Schedule & grace | **Done** | Installments, grace election, EMI window close |
| §6 Early settlement | **Done** | Full + partial settlement paths |
| §7 Guarantor escalation | **Done** | Ladder, transfer, delinquency UI |
| §8 Contribution exemption | **Done** | Active loan exemption in collection cycle |
| Appendices (status, journals) | **Done** | `LoanUserFacingStage`, accounting postings |

---

## Non-spec items (closed May 2026)

| Item | Status |
|------|--------|
| Clickable insight KPIs | **Done** — `InsightKpi`, tenant kpi strip links, `FundOverview` / `MyFundOverview` / central stats |
| Column manager on all tables | **Done** — global `Table::columnManager()` in `AppServiceProvider` |
| Panel visual polish | **Done** — tenant/member/central theme gradients + stat hover animations |
| Server cron documentation | **Done** — callout on **Jobs & commands** page |

---

## Operator checklist

```bash
php artisan tenants:migrate --no-interaction
php artisan test --compact tests/Feature/Tenant/ComplianceLayerTest.php tests/Feature/Tenant/SpecClosureTest.php tests/Feature/Tenant/ReconciliationAndMigrationClosureTest.php
vendor/bin/pint --dirty --format agent
```

Enable host cron: `* * * * * php artisan schedule:run` in each tenant app root.
