# Monthly Collection Cycle — Workflow & Algorithm

## Overview

A five-phase end-to-end process from cycle open to settlement, covering contribution collection, late fee tiers, bank reconciliation, and reporting.

---

## Account Structure

| Account | Purpose |
|---|---|
| Master Bank Account | Cash from bank statement imports |
| Master Cash Account | Cash currently held by the system |
| Master Fund Account | Total value of the fund |
| Member Cash Account | Cash belonging to the member |
| Member Fund Account | Member's contributions to the fund |

**Master Account Invariant (assert nightly):**
- `Master Fund Account = Σ(all Member Fund Accounts)`
- `Master Cash Account = Σ(all Member Cash Accounts)`

---

## Phase 1 — Cycle Initialisation (Day 1)

1. **Compute contribution amounts** — Calculate each member's contribution due for the cycle. Lock amounts into a pending contribution ledger (immutable snapshot — this is the basis for all late fee calculations).
2. **Generate collection notices** — Notify all members of: amount due, due date, grace period end date, and each late fee tier threshold. Set status = `PENDING`.
3. **Snapshot available balances** — Record each member's cash account balance at cycle open. Used to determine whether auto-debit can partially or fully satisfy the contribution.

---

## Phase 2 — Collection Window (Days 1 – N)

### Auto-debit — full balance available

If Member Cash Account balance ≥ contribution due, post immediately on Day 1 (or the configured debit date).

**Journal entry:**

| | Account | Amount |
|---|---|---|
| DR | Member Cash Account | Contribution amount |
| CR | Master Fund Account | Contribution amount |
| CR | Member Fund Account | Contribution amount (memo) |

Status → `COLLECTED`

---

### Partial debit — insufficient balance

If balance < contribution due: debit available balance, mark remaining shortfall as `PARTIALLY PENDING`. Continue monitoring for incoming deposits.

**Journal entry:**

| | Account | Amount |
|---|---|---|
| DR | Member Cash Account | Available balance |
| CR | Master Fund Account | Available balance |
| CR | Member Fund Account | Partial amount (memo) |

Status → `PARTIALLY PENDING`

---

### Real-time deposit matching

Any deposit arriving during the window (bank import or direct cash deposit) triggers an immediate re-evaluation. If the shortfall is covered, post the remainder and mark `COLLECTED`.

> **Design note:** Every time a deposit posts to a member's cash account, the system should immediately check whether it closes an outstanding contribution shortfall — before the deposit becomes available for other uses. This prevents the gap where a member funds their account but the contribution remains flagged as overdue.

---

## Phase 3 — Grace Period Close & Late Fee Engine (Day N+1 onward)

### End-of-window sweep

At close of the collection window, flag all members with outstanding balances as `OVERDUE`. Record `overdue_since` timestamp — this is the immutable clock start for all late fee tier calculations.

> The `days_overdue` counter must start from the exact close-of-business timestamp of the collection window, not the cycle start date. Disputes almost always center on this boundary.

---

### Tiered late fee schedule

| Days overdue | Status | Action |
|---|---|---|
| 1 – 3 | OVERDUE | Reminders only — no fee applied |
| After day 3 | LATE — Tier 1 | Apply Fee X — post to member cash account |
| After day 10 | LATE — Tier 2 | Apply Fee Y (replaces Tier 1) — post to member cash account |
| After day 20 | LATE — Tier 3 | Apply Fee Z (replaces Tier 2) — escalate, flag account |

**Fee model options:**

- **Replacement model (standard):** Each new tier replaces the prior fee. Tier 2 reverses Tier 1 and posts Tier 2.
- **Cumulative model:** Each new tier is added on top of prior fees. Each tier posts an additional DR to the member's cash account.

**Journal entry — late fee application:**

| | Account | Amount |
|---|---|---|
| DR | Member Cash Account | Late fee amount |
| CR | Late Fee Income Account | Late fee amount |

---

### Nightly batch algorithm

```
FOR each member WHERE status IN ('OVERDUE', 'LATE_T1', 'LATE_T2', 'LATE_T3'):
  days = today - overdue_since_date
  new_tier = lookup_tier(days)           // e.g. {3: T1, 10: T2, 20: T3}
  IF new_tier != member.current_tier:
    reverse_prior_fee_entry(member)      // if replacement model
    post_late_fee(member, new_tier.fee)
    update_status(member, new_tier.label)
    notify_member(member)
```

---

## Phase 4 — Settlement & Bank Reconciliation (Ongoing)

### Bank import path

Bank statement lines are imported and matched against pending transactions. On match, the Master Cash Account entry is cleared.

**Journal entry:**

| | Account | Amount |
|---|---|---|
| DR | Master Bank Account | Deposit amount |
| CR | Master Cash Account | Deposit amount |

---

### Direct cash deposit path

Cash deposited directly is posted immediately to the member's Cash Account, then reconciled later with a matching bank import entry.

**Journal entry:**

| | Account | Amount |
|---|---|---|
| DR | Master Cash Account | Deposit amount |
| CR | Member Cash Account | Deposit amount |

---

### Cycle close & carry-forward

At month-end, reconcile Master Bank / Master Cash / Master Fund balances. Any unresolved shortfalls carry into the next cycle with accumulated late fees and escalated status.

---

## Phase 5 — Reporting & Audit Trail

### Collection summary report

Per-member output: amount due, amount collected, outstanding balance, fees applied, current status. Exported for administrator review.

### Immutable audit log

Every journal entry, status change, fee application, and deposit match is written to a tamper-evident log with timestamp and operator ID. Required for dispute resolution and regulatory compliance.

---

## Member Status State Machine

```
PENDING
  ├── balance covers → COLLECTED ✓
  ├── partial balance → PARTIALLY PENDING
  │     ├── deposit covers shortfall → COLLECTED ✓
  │     └── window closes → OVERDUE
  └── window closes (zero balance) → OVERDUE
        └── day 3+ → LATE Tier 1
              └── day 10+ → LATE Tier 2
                    └── day 20+ → LATE Tier 3 (escalate)

Any OVERDUE / LATE state:
  └── payment received → SETTLING → COLLECTED ✓
```

---

## Configuration (per cycle)

Keep these in a configuration table — not hardcoded — to allow adjustments without deployment:

| Parameter | Description |
|---|---|
| `collection_window_days` | Length of the collection window (N days) |
| `tier_1_day_threshold` | Days overdue before Tier 1 fee applies (e.g. 3) |
| `tier_2_day_threshold` | Days overdue before Tier 2 fee applies (e.g. 10) |
| `tier_3_day_threshold` | Days overdue before Tier 3 fee applies (e.g. 20) |
| `fee_tier_1` | Fee amount X |
| `fee_tier_2` | Fee amount Y |
| `fee_tier_3` | Fee amount Z |
| `fee_model` | `replacement` or `cumulative` |

