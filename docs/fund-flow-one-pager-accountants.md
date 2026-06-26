# FundFlow — Accounting & Reconciliation Reference
**Accountant one-pager** · Print or use as a 7-slide deck

---

### Slide 1 — Chart of accounts (tenant)

| Account | Type | Normal balance | Pool mirror |
|---------|------|----------------|-------------|
| Master Bank | Asset | Debit | — |
| Master Cash | Asset | Debit | Σ member cash |
| Master Fund | Equity/pool | Credit | Σ member fund |
| Member Cash | Asset (per member) | Debit | ↔ master cash |
| Member Fund | Equity (per member) | Credit | ↔ master fund |
| Loan sub-account | Contra / tracking | Varies | Per loan |
| Master Fees | Revenue/reserve | Credit | Master only |

---

### Slide 2 — Pool invariants (nightly)

| Code | Check |
|------|-------|
| `MASTER_CASH_POOL_DRIFT` | `master_cash.balance` ≠ Σ `member_cash.balance` |
| `MASTER_FUND_POOL_DRIFT` | `master_fund.balance` ≠ Σ `member_fund.balance` |
| `MEMBER_CASH_DRIFT` | Component formula vs stored cash balance |
| `MEMBER_FUND_DRIFT` | Component formula vs stored fund balance |

Mirroring is suppressed mid-transaction (`masterPoolMirrorInProgress`) — realtime checks skip during paired postings.

---

### Slide 3 — Standard journal patterns

**Contribution collection**

| DR | CR |
|----|-----|
| Member cash | |
| Master cash (mirror) | |
| | Member fund |
| | Master fund (mirror) |

**Loan disbursement**

| DR | CR |
|----|-----|
| Member fund (portions) | |
| Master fund (mirror) | |
| Loan account (principal) | |
| | Member cash |
| | Master cash (mirror) |

**Cash-out approval**

| DR | CR |
|----|-----|
| Member cash | |
| Master cash (mirror) | |

Plus uncleared `bank_transactions` row — clearance updates flags only.

**Loan repayment (import / EMI)**

| Step | DR | CR |
|------|----|-----|
| Cash-in mirror | Member + master cash | |
| Collection | | (paired debit same accounts) |
| Fund leg | | Member + master fund |
| Principal | | Loan sub-account |

---

### Slide 4 — Bank clearance vs ledger

| Concept | Ledger | Bank clearance |
|---------|--------|----------------|
| Deposit accept | CR cash (intent) | Uncleared line → match import |
| Cash-out accept | DR cash (intent) | Uncleared line → match import |
| Mirror to cash | Master bank ↔ master cash | Statement status change |
| Clear / Match | **No additional legs** | `is_cleared` linkage |

**Do not** pass `member_id` on master cash legs unless documented — triggers `onMemberCashIncreased()` auto-collection.

---

### Slide 5 — Loan funding split

At disbursement, `member_portion` + `master_portion` = `amount_approved`.

| Portion | Economic meaning | Repayment obligation |
|---------|------------------|----------------------|
| Member portion | Equity already in pool | Not collected via EMI |
| Master portion | Pool exposure | Collected via EMI |
| Settlement threshold | % of approved (e.g. 16%) | Collected via EMI |

**Fully member-funded** (`master_portion` = 0, `settlement_threshold` = 0): no EMI schedule; loan completed at import. Principal applied at disbursement via fund debit + loan account credit.

Repayment target: `master_portion + (amount_approved × settlement_threshold)`.

---

### Slide 6 — Legacy migration notes

| Issue | Cause | Remediation |
|-------|-------|-------------|
| Cash balance ≠ txn net | Repair deleted txs without balance adjust | `php artisan accounting:rebuild-balances --tenants=…` |
| Phantom cash after reclassify | `reverseContributionPrincipal` + raw `delete()` | Fixed in `ContributionService` migration repair |
| Pending EMI on fund-only loan | Import created schedule despite zero obligation | `legacy:rebuild-loan-installments` |

Legacy repayments use paired cash mirrors (credit then debit) — net cash zero per payment; fund leg carries economic effect.

---

### Slide 7 — Reconciliation workflow

```
Import bank CSV
    → Mirror to master cash (master bank leg)
    → Post to member (member cash leg)
    → Auto-collection (contributions / EMIs)
    → Nightly invariants
    → Investigate exceptions → manual correction journals
```

**Key services:** `AccountingService` (mirror helpers), `FundPostingService`, `LoanLedgerService`, `MemberCashOutService`, `MemberInvariantService`, `ReconciliationCorrectionService`.

**References:** `docs/fund-flow-dynamics.md` · `docs/fund-flow-reconciliation-and-scheduler.md` · `.cursor/rules/accounting-master-member-sync.mdc` · `docs/collection_cycle_workflow.md`

---

*For component-level cash formula see `MemberInvariantService` §5.13.*
