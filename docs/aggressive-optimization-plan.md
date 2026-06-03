# Aggressive Optimization Plan

## Goal

Drive higher-impact optimization across runtime-heavy paths and structural service workflows while preserving ledger correctness, reconciliation integrity, and approval/collection behavior.

## Scope

The plan is split into two waves:

- **Phase 1:** Runtime performance hotspots
- **Phase 2:** Structural consolidation and workflow primitives

---

## Phase 1: Runtime Performance Hotspots

### 1) Reconciliation batch scans and parity checks

- Refactor reconciliation scan paths to reduce per-row queries and lookup churn.
- Push mismatch filtering to SQL where possible.
- Preserve existing exception codes and entity payload semantics.

**Status:** Completed

### 2) Delinquency arrears generation at scale

- Replace per-member/per-period contribution lookups with preloaded contribution maps.
- Reuse a shared unpaid-period builder for single-member and table-generation paths.

**Status:** Completed

### 3) Bank-clearing candidate selection and matching cost

- Narrow candidate sets in SQL using amount tolerance and date windows.
- Remove redundant in-memory candidate filtering.

**Status:** Completed

### 4) Contribution collection repeated lookups

- Compress repeated household-dependent queries during member-cash increase settlement.
- Reuse preloaded active dependents across household contribution and repayment passes.

**Status:** Completed

### 5) Insights aggregate fan-out consolidation

- Replace repeated count/sum/exists fan-out with consolidated aggregate queries.
- Keep dashboard payload shapes and labels stable.

**Status:** Completed

---

## Phase 2: Structural Consolidation

### 1) Shared review workflow primitives

- Introduce a common service for operational review metadata updates:
  - `status`
  - `reviewed_by`
  - `reviewed_at`
  - `admin_remarks`
- Centralize admin notification fan-out for operational review flows.

**Status:** Completed

### 2) Synthetic bank placeholder factory

- Introduce a synthetic statement factory for operational buckets.
- Migrate fund posting and cash-out statement creation callers to shared factory methods.

**Status:** Completed

### 3) Centralized clearance linkage payload resolver

- Centralize clear/match linkage payload mapping by flow type:
  - fund posting
  - cash-out
- Add strict unit coverage for resolver output contracts.

**Status:** Completed

### 4) Membership approval posting pipeline

- Extract approval posting stages into a dedicated pipeline with explicit stage ordering:
  1. mark approved
  2. prepare cutoff (if CSV-imported)
  3. post subscription fee effects
  4. post opening balances

**Status:** Completed

---

## Validation Strategy Used

- Run formatter after each substantive pass:
  - `vendor/bin/pint --dirty --format agent`
- Run targeted and dependent regression suites after each phase step, including:
  - bank clearing
  - fund posting
  - cash-out
  - loan delinquency/default
  - membership approval/import-cutoff/subscription-fee flows
  - portal/member insights

## Current Plan State

- **Phase 1:** Fully complete
- **Phase 2:** Fully complete
