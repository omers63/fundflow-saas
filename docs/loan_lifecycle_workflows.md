# Loan Lifecycle — Full Workflow & Algorithm Reference

---

## System account structure

| Account | Purpose |
|---|---|
| Master Bank Account | Cash from bank statement imports |
| Master Cash Account | Cash currently held by the system |
| Master Fund Account | Total value of the fund |
| Member Cash Account | Cash belonging to the member |
| Member Fund Account | Member's contributions to the fund |

**Master account invariant (assert nightly):**
- `Master Fund Account = Σ(all Member Fund Accounts)`
- `Master Cash Account = Σ(all Member Cash Accounts)`

---

## 1. Loan eligibility & admin override

### Eligibility gates (evaluated in sequence)

**Gate 1 — Membership tenure**
Member must have at least X years of active membership, computed from join date to request date.
> Fail → request rejected. Admin override can bypass this gate.

**Gate 2 — Minimum fund balance**
Member's Fund Account balance must be ≥ Y amount at time of request.
> Fail → request rejected. Admin override can bypass this gate.

**Gate 3 — Delinquency check**
Member must have no active delinquency flag (missed EMIs, overdue contributions). Delinquent members are blocked from new loan requests.
> Delinquent → request blocked. Admin override required to proceed.

**Gate 4 — Active loan count**
Member may not exceed X active loans simultaneously (typically 1–2). A loan is active from first disbursement until full repayment.
> Exceeds limit → request rejected. Admin override can bypass.

**Gate 5 — Borrow limit**
Requested amount must be ≤ X × Member Fund Account balance. Applies regardless of which fund tier the loan is drawn from.
> Exceeds multiplier → request rejected or admin may adjust amount.

**Gate 6 — Guarantor assignment**
Every loan request must name a guarantor — an active member in good standing. The guarantor may not be the same person as the borrower. Guarantor eligibility follows the same delinquency rule.
> No valid guarantor → request cannot be submitted. This gate cannot be overridden.

---

### Admin override matrix

| Gate | Admin can override? | Logged? |
|---|---|---|
| Tenure (X years) | Yes | Yes — mandatory reason required |
| Min fund balance (Y) | Yes | Yes — mandatory reason required |
| Delinquency flag | Yes | Yes — mandatory reason required |
| Active loan count | Yes | Yes — mandatory reason required |
| Borrow multiplier | Yes | Yes — mandatory reason required |
| No guarantor | **No** | N/A |

---

## 2. Loan request submission & queue management

### Submission flow

1. **Member submits loan request**
   Input: requested amount, loan type (standard or emergency), guarantor ID, grace period election (0, 1, or 2 cycles). System snapshots member's fund balance and active loan count at submission time.

2. **Eligibility gates evaluated**
   All gates from Section 1 are evaluated in sequence. Any failure either rejects the request or raises an admin override flag.
   > If admin override was used: request enters the queue tagged OVERRIDE with override reason logged.

3. **Loan tier assignment**
   System maps the requested amount to an EMI tier (see Section 3). The tier determines the EMI amount and the associated fund tier from which the loan will be drawn.

4. **Queue insertion**
   Standard requests are appended to the FIFO queue ordered by submission timestamp. Emergency requests are inserted at the front of the queue ahead of all pending standard requests. Multiple emergencies among themselves follow FIFO order.
   > Queue is processed by admin in order. Admin may not skip positions except via the emergency priority mechanism.

5. **Status → PENDING_ADMIN**
   Member and guarantor are notified of queue position. Request stays in PENDING_ADMIN until admin processes it.

---

### EMI tier lookup (configurable)

| Loan amount range | EMI |
|---|---|
| 1K – 10K | 1K |
| 11K – 30K | 1.5K |
| 31K – 60K | 2.5K |
| Emergency | Configurable per request |

---

### Queue insertion algorithm

```
FUNCTION insert_to_queue(request):
  IF request.type == 'EMERGENCY':
    position = front_of_queue()
  ELSE:
    position = end_of_queue()
  queue.insert(request, position)
  assign_queue_number(request)
  notify(member, guarantor, position)
  set_status(request, 'PENDING_ADMIN')
```

---

## 3. Fund tier structure & availability

Fund tiers define how much of the Master Fund Account is available to fund loans in each loan tier. Tiers are configurable from 0%–100% and may overlap.

### Fund tier model

| Fund tier | Linked loan tier | Allocation | Overlaps allowed? |
|---|---|---|---|
| Fund Tier A | Loan Tier 1 (1K–10K) | 0–100% configurable | Yes |
| Fund Tier B | Loan Tier 2 (11K–30K) | 0–100% configurable | Yes |
| Fund Tier C | Loan Tier 3 (31K–60K) | 0–100% configurable | Yes |
| Fund Tier E (Emergency) | None — emergency requests only | 0–100% configurable | Yes |

> Overlap means the same portion of the Master Fund Account may count toward multiple fund tiers. Availability is checked at disbursement time against the current Master Fund balance × tier percentage.

---

### Loan allocation split

Each loan is funded from two sources, computed at approval time:

| Portion | Definition |
|---|---|
| Member's portion | Member Fund Account balance (up to loan amount) |
| Master's portion | Loan amount − Member's portion |
| Repayment threshold | Master portion + X% of requested loan amount |

> The loan is considered **fully repaid** when the master fund portion plus the X% settlement threshold has been repaid. The member's own portion represents their equity in the fund and is excluded from the guarantor's liability.

---

### Fund availability check algorithm

```
FUNCTION check_fund_availability(loan_tier, amount):
  tier_pct  = config.fund_tier[loan_tier].allocation_pct
  tier_pool = master_fund_balance * tier_pct
  committed = sum of all active disbursements against this tier
  available = tier_pool - committed
  RETURN available >= amount   // full approval
      OR available > 0          // partial disbursement possible
```

---

## 4. Approval, partial disbursement & activation

### Approval flow

1. **Admin reviews next request in queue**
   Admin sees: member name, requested amount, loan tier, fund tier availability, queue position, override flags. Emergency requests are clearly marked.

2. **Fund tier availability evaluated**
   System checks available balance in the associated fund tier.
   - Sufficient funds → full approval available.
   - Partial funds → admin may disburse partial amount.
   - No funds → request deferred or rejected.

3. **Admin decision: approve / partial / reject**
   Admin records decision with optional notes. Partial approval requires specifying the disbursed amount. Rejection requires a reason.

4. **Disbursement posted (per tranche)**
   Each disbursement — whether partial or full — triggers the following journal entries immediately:

   | | Account | Amount |
   |---|---|---|
   | DR | Master Fund Account | Disbursed amount |
   | CR | Member Cash Account | Disbursed amount |
   | Memo | Member Fund Account | −Disbursed amount (reflected) |

   > The member's cash account is credited. The member may then request a cash-out via a separate cash-out process flow.

5. **Loan activation check**
   The loan is NOT active until the total approved amount has been fully disbursed. Partial disbursements accumulate under status PARTIALLY_DISBURSED.
   - Fully disbursed → status = ACTIVE. Repayment schedule is built (see Section 5).
   - Partially disbursed → awaiting further tranches. No repayment schedule yet. Grace period clock has not started.

6. **Member & guarantor notified**
   On full activation: notify of loan amount, repayment schedule start date (accounting for grace period election), EMI amount, and full repayment threshold.

---

## 5. Repayment schedule construction & grace period

### Grace period election

The member elects their grace period preference at request submission time. This election is locked and cannot be changed after approval.

| Election | Effect |
|---|---|
| 0 cycles (no grace) | First EMI due in the immediate next collection cycle |
| 1 cycle grace | First EMI due in cycle N+2 (one cycle skipped) |
| 2 cycles grace | First EMI due in cycle N+3 (two cycles skipped) |

> Grace cycles are interest-free deferral — no EMI and no late fee during the grace window. The contribution exemption still applies during grace periods.

---

### Schedule construction algorithm

```
FUNCTION build_schedule(loan):
  emi          = lookup_emi_tier(loan.amount)
  threshold    = loan.master_portion + (loan.amount * settlement_pct)
  grace_cycles = loan.grace_election          // 0, 1, or 2
  start_cycle  = current_cycle + 1 + grace_cycles
  cycle        = start_cycle
  total_repaid = 0
  schedule     = []

  WHILE total_repaid < threshold:
    schedule.append({cycle, emi, status: 'PENDING'})
    total_repaid += emi
    cycle        += 1

  // Last instalment adjusted to exact remainder
  schedule[-1].amount = threshold - (total_repaid - emi)
  loan.repayment_schedule = schedule
  loan.full_repayment_threshold = threshold
  RETURN schedule
```

---

### Repayment collection

Each EMI cycle is collected via the exact same mechanism as monthly contributions: auto-debit from member cash account, partial debit if insufficient, real-time deposit matching, and the identical tiered late fee schedule.

**Journal entry — EMI collection:**

| | Account | Amount |
|---|---|---|
| DR | Member Cash Account | EMI amount |
| CR | Master Fund Account | EMI amount |
| CR | Loan Repayment Ledger | EMI amount (memo) |

---

## 6. Early settlement — full & partial

### Full early settlement

1. Member requests full settlement. System computes outstanding threshold balance: `threshold − total_repaid_to_date`. Member must fund their cash account with this amount before settlement is posted.

2. Settlement journal entry:

   | | Account | Amount |
   |---|---|---|
   | DR | Member Cash Account | Remaining threshold |
   | CR | Master Fund Account | Remaining threshold |

3. Loan status → REPAID. Contribution exemption lifted next cycle. Guarantor liability released.

---

### Partial early settlement

1. **Member specifies partial amount.** Amount must be ≥ 1 full EMI. System validates that the remaining balance after payment still meets the minimum threshold trajectory.

2. **Member elects schedule treatment:**

   **Option A — Roll-up (compress):** Partial payment is applied forward. Upcoming EMI cycles that are fully covered by the payment are marked as settled. The remaining schedule shortens (fewer cycles remain).

   **Option B — Skip cycles:** Partial payment covers X upcoming cycles which are skipped. The original schedule length is preserved — the member has X cycles of relief before EMIs resume.

3. **Schedule rebuilt.** System rebuilds the repayment schedule from the current cycle forward applying the chosen option. Member and guarantor are notified of the updated schedule.

   | | Account | Amount |
   |---|---|---|
   | DR | Member Cash Account | Partial amount |
   | CR | Master Fund Account | Partial amount |

---

### Partial settlement schedule rebuild algorithm

```
FUNCTION apply_partial_settlement(loan, amount, option):
  remaining_threshold = loan.threshold - loan.total_repaid
  cycles_covered = floor(amount / loan.emi)

  IF option == 'ROLLUP':
    mark next cycles_covered pending EMIs as SETTLED
    rebuild remaining schedule from (current + cycles_covered + 1)

  IF option == 'SKIP':
    mark next cycles_covered pending EMIs as SKIPPED
    resume EMIs from (current + cycles_covered + 1)
    // total cycle count stays the same; gaps are inserted

  loan.total_repaid += amount
  IF loan.total_repaid >= loan.threshold:
    close_loan(loan)
```

---

## 7. Guarantor liability & delinquency escalation

### Missed EMI escalation ladder

| Missed EMIs | Action | Borrower status |
|---|---|---|
| 1 – (X−1) | Late fees apply per collection cycle rules | Overdue |
| X missed | Guarantor notified — formally warned of upcoming liability | Delinquent |
| Y missed | Guarantor assumes full liability; loan transferred | Suspended |

---

### Guarantor liability scope

The guarantor is responsible **only for the master fund portion** of the remaining repayment — the portion originally drawn from the Master Fund Account, plus the settlement threshold percentage. The member's own fund equity portion is excluded.

| Component | Definition |
|---|---|
| Remaining master portion | (Master portion of original loan) − (repayments already credited to master portion) |
| + Settlement threshold | X% of original requested loan amount |
| = Guarantor obligation | Total amount guarantor must repay |

---

### Loan transfer flow

1. **Automatic loan transfer at Y missed EMIs.** System re-assigns loan ownership to the guarantor. The guarantor becomes the borrower of record for the remaining obligation.

2. **Borrower suspended.** Original borrower's account is flagged SUSPENDED. Suspended members cannot request new loans, make withdrawals, or submit transactions requiring admin approval until the loan is fully resolved and admin lifts the suspension.
   > A suspended member cannot be a guarantor on any other loan.

3. **Guarantor repayment schedule rebuilt.** A new schedule is built for the guarantor based on the remaining obligation. The guarantor's active loan count is incremented (counts against their active loan limit). Late fees apply to the guarantor's repayments under the same collection cycle rules.

4. **Resolution & reinstatement.** Once the transferred loan is fully repaid by the guarantor, admin may review the original borrower's suspension. Reinstatement is not automatic — it requires an explicit admin action.

---

### Escalation algorithm

```
NIGHTLY_JOB: evaluate_missed_emis()
  FOR each active loan:
    missed = count EMIs with status MISSED or OVERDUE

    IF missed == X:
      notify(guarantor, 'liability_warning')
      set_borrower_status('DELINQUENT')

    IF missed >= Y:
      guarantor_obligation = calc_guarantor_liability(loan)
      transfer_loan(loan, new_owner=guarantor)
      suspend_member(loan.original_borrower)
      rebuild_schedule(guarantor, guarantor_obligation)
      notify(guarantor, 'liability_active')
      notify(borrower,  'account_suspended')
```

---

## 8. Contribution exemption during loan repayment

A member under an active loan repayment schedule is exempt from making standard monthly contributions to the fund. This prevents double-charging members who are already servicing a loan.

### Exemption rules

| Condition | Contribution required? | Notes |
|---|---|---|
| Loan ACTIVE, in repayment | Exempt | No contribution debit for this cycle |
| Loan in grace period (pre-repayment) | Exempt | Grace cycles also exempt |
| Loan PARTIALLY_DISBURSED | Exempt | Exempt from activation date onward |
| Loan REPAID this cycle | Due | Exemption lifts; contribution due next cycle |
| Loan transferred to guarantor — original borrower | Due | Borrower is suspended but contributions resume |
| Loan transferred to guarantor — guarantor | Exempt | Guarantor is now in repayment; standard exemption applies |
| Multiple active loans | Exempt | One active loan is sufficient for full exemption |

---

### Collection cycle integration algorithm

```
MONTHLY_COLLECTION_JOB:
  FOR each member:
    IF has_active_loan(member) OR in_grace_period(member):
      skip_contribution_debit(member)
      log('EXEMPT — active loan', member, cycle)
    ELSE:
      proceed_with_contribution_collection(member)
```

---

### Fund account continuity note

While exempt from contributions, the member's Fund Account balance does not grow during the loan period. This directly affects future loan eligibility, since the borrow limit is computed as X × Fund Account balance. Members should be informed that loan repayment periods pause their fund accumulation.

---

## Appendix — Configurable parameters

All of the following should be stored in a configuration table, not hardcoded, to allow cycle-by-cycle adjustment without deployment.

| Parameter | Description |
|---|---|
| `min_membership_years` | Minimum years of membership before a loan can be requested (X) |
| `min_fund_balance` | Minimum fund account balance required to request a loan (Y) |
| `borrow_multiplier` | Maximum loan amount as a multiple of fund balance (X×) |
| `max_active_loans` | Maximum number of simultaneous active loans per member |
| `settlement_threshold_pct` | % of requested loan added to master portion to define full repayment threshold |
| `grace_period_options` | Allowed grace period elections (e.g. 0, 1, 2 cycles) |
| `emi_tiers` | Table of loan amount ranges and their associated EMI amounts |
| `fund_tier_allocations` | Per-tier % of master fund available for each loan tier |
| `late_fee_tier_days` | Day thresholds for Tier 1 / 2 / 3 late fees (e.g. 3, 10, 20) |
| `late_fee_amounts` | Fee amounts X, Y, Z for each late fee tier |
| `late_fee_model` | `replacement` or `cumulative` |
| `guarantor_warning_threshold` | Missed EMIs before guarantor notification (X) |
| `guarantor_liability_threshold` | Missed EMIs before automatic loan transfer (Y) |
| `collection_window_days` | Length of each monthly collection window |

---

## Appendix — Loan status state machine

```
PENDING_ADMIN
  ├── rejected → REJECTED (terminal)
  └── approved (partial) → PARTIALLY_DISBURSED
        └── fully disbursed → ACTIVE
              ├── (grace period) → ACTIVE [no EMI due yet]
              ├── in repayment → ACTIVE
              │     ├── early full settlement → REPAID (terminal)
              │     ├── early partial settlement → ACTIVE [schedule rebuilt]
              │     ├── X missed EMIs → DELINQUENT [guarantor warned]
              │     └── Y missed EMIs → TRANSFERRED [guarantor owns loan]
              │           └── guarantor repays fully → REPAID (terminal)
              └── all EMIs collected → REPAID (terminal)
```

---

## Appendix — Journal entry summary

| Event | DR | CR |
|---|---|---|
| Loan disbursement | Master Fund Account | Member Cash Account |
| Disbursement memo | Member Fund Account (−) | — |
| EMI collection | Member Cash Account | Master Fund Account |
| Late fee — loan EMI | Member Cash Account | Late Fee Income Account |
| Full early settlement | Member Cash Account | Master Fund Account |
| Partial early settlement | Member Cash Account | Master Fund Account |
| Monthly contribution (non-exempt) | Member Cash Account | Master Fund Account |
| Late fee — contribution | Member Cash Account | Late Fee Income Account |
| Bank import clearing | Master Bank Account | Master Cash Account |
| Direct cash deposit | Master Cash Account | Member Cash Account |
