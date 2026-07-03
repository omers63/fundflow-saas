# FundFlow — Reconciliation & Scheduler
**Accountant one-pager** · Print or use as a 6-slide deck

Full reference: [fund-flow-reconciliation-and-scheduler.md](fund-flow-reconciliation-and-scheduler.md)

---

### Slide 1 — Three mechanisms (do not confuse them)

| Mechanism | UI / command | Output | Your use |
|-----------|--------------|--------|----------|
| **Realtime snapshot** | **Run check now** / `fund:reconcile --realtime` | `reconciliation_snapshots` | Point-in-time ledger audit |
| **Daily snapshot** | **Daily snapshot** / `fund:reconcile --daily` | Same checks + yesterday period metrics | Daily audit trail |
| **Monthly snapshot** | **Monthly snapshot** / `fund:reconcile --monthly` | Same checks + prior month period metrics | Month-end pack |
| **Exception queue** | **Exception queue re-check** / `fund:nightly-reconciliation` | Live `reconciliation_exceptions` | Active remediation (rebuilds queue) |
| **Realtime guards** | (automatic on each `Transaction`) | Immediate exceptions | Catch drift at post time |

**Posting ≠ clearance ≠ reconciliation.** Match/clear bank lines updates linkage — no extra cash journal when correct.

---

### Slide 2 — Daily morning sequence (per tenant)

| Time | Command | Purpose |
|------|---------|---------|
| 06:00 | `fund:assert-master-invariants` | Master cash/fund = Σ members |
| 06:20 | `fund:reconcile --daily` | Store yesterday's audit snapshot |
| 06:30 | `fund:nightly-reconciliation` | Full sweep + auto-resolve + halt gate |
| 07:15 | `contributions:apply-late-fees` | Late fee tiers (halt-sensitive) |
| 08:00 | `bank:auto-match` | Match imports ↔ uncleared postings |

**UI:** Finance → Reconciliation (queue) · Audit & System → Jobs (manual re-run).

---

### Slide 3 — Nightly batch flow

```
NIGHTLY_RECON_START
  → clear exception queue (fresh sweep)
  → master balanced? (tiny drift → rounding suspense)
  → domain sweeps: contributions · loans/EMI · fund tiers · bank · late fees · member formulas
  → attemptAutoResolve on open exceptions (metadata only; ledger fixes need admin Retry auto-resolve)
  → re-assert master balanced
NIGHTLY_RECON_COMPLETE
```

**Critical halt:** `MASTER_IMBALANCE_UNRESOLVED` → `BatchPostingGate` blocks collection/EMI/bank batch jobs until fixed.

---

### Slide 4 — Realtime vs nightly checks

| When | What | Example codes |
|------|------|---------------|
| **Each transaction** | Journal balance, member drift, master pool drift | `UNBALANCED_ENTRY`, `MEMBER_*_DRIFT`, `MASTER_*_POOL_DRIFT` |
| **Nightly only** | Workflow signals, bank pipeline, EMI state, fee tiers | `PENDING_PAST_WINDOW_CLOSE`, `RECON_UNMATCHED_BANK_LINE`, `EMI_COLLECTED_LEDGER_MISSING` |

Realtime checks **skip** during `masterPoolMirrorInProgress()` — paired mirrors post in one logical operation.

---

### Slide 5 — Monthly calendar (collection)

| Day | Command | Effect |
|-----|---------|--------|
| 1st | `contributions:init-cycle` | Pending rows created |
| 1st | `contributions:notify` | Due notifications |
| 5th | `contributions:apply` | Batch cash → fund |
| 6th | `loans:apply-repayments` | Batch EMI from cash |
| 6th | `contributions:close-window` | Unpaid → overdue |
| 2nd | `fund:reconcile --monthly` | Month audit snapshot |

**Between batch dates:** any cash credit (deposit, import, disbursement) can trigger auto-collection via `onMemberCashIncreased`.

---

### Slide 6 — Investigation quick path

```
Symptom → Reconciliation queue (read code + presenter hint)
       → trace Transaction(s) in audit log
       → bank clearing if cash-related
       → correction (reverse / reclassify / journal / CLI repair)
       → fund:nightly-reconciliation or wait for 06:30
       → fund:assert-master-invariants
```

| CLI | When |
|-----|------|
| `fund:reconcile --realtime --no-store` | Point-in-time audit without saving |
| `accounting:rebuild-balances --dry-run` | Stored balance ≠ txn sum |
| `legacy:repair-excess-loan-repayments` | Repayments > fund-portion target |

**Manual:** [manual-accountant.md](manual-accountant.md) · **Journal patterns:** [fund-flow-one-pager-accountants.md](fund-flow-one-pager-accountants.md)
