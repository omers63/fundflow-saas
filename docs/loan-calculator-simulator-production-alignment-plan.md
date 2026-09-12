# Loan calculator and simulator → production alignment plan

**Status:** Draft — documentation only; production behavior is unchanged until the phases below land.  
**Last updated:** 2026-08-24  
**Golden reference:** member Loan calculator (Estimate) and Lifecycle simulator. Production must match their **economic outcomes and schedule math**.

**Related**

- [loan-lifecycle-simulator-intent-vs-live.md](./loan-lifecycle-simulator-intent-vs-live.md) — current simulator intent vs live snapshot
- [loan_lifecycle_workflows.md](./loan_lifecycle_workflows.md) — production workflow reference (will drift until this plan is implemented)
- Simulator: `App\Services\MemberLoanLifecycleSimulator`
- Calculator: `App\Services\MemberLoanCalculatorService`
- Tests that define the contract: `tests/Unit/MemberLoanLifecycleSimulatorTest.php`, `tests/Unit/MemberLoanCalculatorServiceTest.php`

---

## 1. Framing

The calculator and simulator are the product rules. Production already does **more** than they do (cash payout, bank clearing, master-pool mirrors, delinquency, guarantors). Alignment means matching **fund outcomes and installment schedules**, not copying the simulator’s two-bucket ledger.

### 1.1 What is already aligned

These already share settings and formulas:

| Topic | Shared source |
|---|---|
| Member vs master portions | `LoanSettings::resolveFundingPortions()` |
| Maturity | `master_portion + loan × settlement%` |
| EMI count | `ceil(maturity / min_installment)`; last row absorbs remainder on **initial** build |
| Excess on configured split | `max(0, fund − member_portion)` |
| Eligibility after close | fund ≥ `% × tier ceiling` (`Loan::eligibilityThresholdAmount()`) |
| Disbursement **fund** effect | debit member fund for member slice **and** master slice (balance may go negative) |
| Excess keep / cash-out / roll-up / skip **at disbursement** | live `LoanSplitExcessFund*` services |

### 1.2 What we must not copy from the simulator

| Simulator simplification | Production must keep |
|---|---|
| No cash credit for the loan payout | `LoanLedgerService::postPartialLoanDisbursement()` credits member cash + master cash |
| Repayment credits fund with no cash debit | EMI: debit cash, then credit fund when the row is `paid` (same fund climb, real cash) |
| `applyCashDeposit` is a fake integer bump | Uncleared bank line, match later, **no extra loan journal on clear** |
| No late fees / default / guarantor | Keep as an overlay on the golden path |
| No master accounts | Keep `credit/debitMember*WithMasterMirror` |

Golden **fund after disbursement** is:

```text
pre − member_portion − master_portion − excess_to_cash
```

Live already does that on the fund side. Do not remove the cash payout.

### 1.3 Ledger translation

Treat simulator `fund_balance` as live **member fund**. Treat simulator `cash_balance` as live **member cash**.

| Event | Golden (simulator) | Live equivalent |
|---|---|---|
| Disbursement | Debit fund only | Debit funds **and** credit member/master cash (payout). Fund delta matches golden. |
| Regular / partial repayment | Credit fund by applied amount | Debit cash, credit fund when installment(s) become `paid`. |
| Overpay above remaining maturity | Extra stays in cash | Same: surplus remains member cash. |
| Full early settlement | Jump fund to `pre_loan_fund`; **no cash debit**; cancel remaining rows | Must **not** be implemented as “pay the remaining EMI stack from cash” (that yields a Paid-like fund, not restored fund). |

---

## 2. Correct golden formulas

The older intent doc used a wrong full-settlement formula. **Code and tests** are authoritative.

### 2.1 Full early settlement

`MemberLoanLifecycleSimulator::fullSettlementAmount()` / `applyFullEarlySettlement()`:

```text
required = max(0, pre_loan_fund − fund_balance)
fund     = pre_loan_fund
schedule = cancel remaining rows
status   = fully_settled
```

Do **not** use `outstanding_fund_portion + (pre − current)` (that double-counts the hole in fund).

**Diagram (50/50, loan 100k, pre-loan fund 50k):**

| State | Fund | Restore required | Fund after full settle |
|---|---|---|---|
| At disbursement | −50k | **100k** | **50k** |
| After 5k regular | −45k | 95k | 50k |

Live today: pay remaining EMIs from cash → fund ends near **10k** (Paid-like), not 50k.

### 2.2 Regular payment / overpay

Any amount ≥ min EMI applies to remaining maturity, credits fund, **rebuilds the pending schedule** (remainder on the **first** pending row). Extra above remaining maturity stays cash.

### 2.3 Partial early settlement

Amount may be **below one EMI** (e.g. 1000 on a 2500 EMI). Remaining faces are redistributed. Roll-up drops tail rows. Skip keeps calendar dates. Mid-life member UI always uses **roll-up**; skip is a disbursement approach (and admin).

### 2.4 Normal Paid

When `remaining_maturity` hits 0:

```text
fund = max(fund, top_up)
```

Exact payoff lands on top-up. Overpay surplus is **cash**, not extra fund. Eligibility usually still fails until post-close contributions (`top_up` vs `% × tier ceiling`).

### 2.5 Current-loan settlement when projecting fund

`MemberLoanCalculatorService::fundProjection()`:

```text
projected_fund = current_fund + loan_repayment_amount + cycles_added × contribution
cash_needed     = max(0, loan_repayment_amount) + cycles_added × contribution
```

| Mode | `loan_repayment_amount` | Contribution cycles |
|---|---|---|
| Regular payments | Sum of one unpaid EMI per window cycle | Leftover cycles |
| Partial to maturity | Sum of **all** remaining unpaid EMIs | All window cycles |
| Full early settlement | `pre_loan_fund − current_fund` (prefer `member_fund_balance_at_disbursement`) | All window cycles |

---

## 3. Gap inventory (golden → live)

### 3.1 Regular payment and overpay (largest behavioral gap)

**Golden:** amount ≥ min EMI applies to remaining maturity and rebuilds pending rows.

**Live:**

- Collection only takes EMIs **due in the open window**
- `LoanService::recordRepayment()` **rejects** amount ≠ that EMI
- Surplus cash sits in cash and does **not** shorten or re-amount the horizon

### 3.2 Partial early settlement

**Golden:** sub-EMI lumps allowed; remaining faces redistributed.

**Live:** `LoanEarlySettlementService::partialEarlySettle()` requires **≥ one full EMI**, pays from **cash**, and does not rebuild remaining faces. After roll-up, `Loan::ensureScheduleInstallmentAmount()` can still target the **original** `fullRepaymentThreshold()`, which undoes the shortened horizon.

### 3.3 Full early settlement (policy gap)

**Golden:** restore fund; cancel schedule; no cash debit of the EMI stack.

**Live:** `LoanEarlySettlementService::earlySettle()` requires cash ≥ remaining EMIs + late fees, marks those rows `paid`, status `early_settled`. Fund only rises by those EMI principals.

**Economic implication of matching golden:** the member keeps disbursement cash **and** fund returns to pre-loan. That is a real policy change. This plan treats the simulator as source of truth. If finance instead wants unused disbursement cash returned, that is a **separate** “return unused payout” step, not EMI payoff.

### 3.4 Normal Paid: fund ≥ top-up

**Golden:** `markPaid` sets `fund = max(fund, top_up)`.

**Live:** `completed` does not snap fund. `Loan::isReadyToSettle()` can complete when `repaid_to_master ≥ master` **and** fund ≥ top-up **without all EMIs paid**. `syncPaidOffStatusFromInstallments()` completes when all rows are paid, with **no** fund floor.

### 3.5 Applying / eligibility

**Golden estimate** only blocks:

- projected fund &lt; 0
- member portion &gt; projected fund

It does **not** use tenure, min fund 6000, delinquency, or max active loans. It **does** let you assume the current loan is settled by start date (three modes above).

**Live apply** (`LoanEligibilityService::getFailedGates()`) blocks on standing gates and **one in-progress loan**. There is no “settle current then apply” using the calculator modes.

### 3.6 Grace and first EMI date

**Golden:** start-cycle paid vs unpaid + N grace rows via `estimatedSchedule()`; due dates from `LoanRepaymentWindowPolicy`.

**Live:** `Loan::computeExemptionAndFirstRepayment()` uses **cutoff day** (cycle start − 1), not the calculator’s “contribution posted this cycle” story.

### 3.7 Collection vs simulator clicks

**Golden:** member chooses when to pay; EMI is not auto-taken from simulated cash (except skip-from-excess at start).

**Live:** `ContributionCollectionCycleService::onMemberCashIncreased()` auto-collects due EMIs (contributions first). Extra cash never becomes extra EMIs (gap 3.1).

**Keep:** auto-collect for **due scheduled EMI** in the open window. Lumps and overpay go through apply-toward-maturity, not silent extra collections.

### 3.8 Delinquency, clearing, reconciliation

| Topic | Gap | Plan |
|---|---|---|
| Late fees, overdue, freeze, default, guarantor | Simulator ignores them | Keep as overlay; do not strip. Optional later: “with delinquency” simulator mode |
| Bank clearing | Simulator has no bank | Unchanged. Restore/overpay journals must **not** post extra legs on statement match |
| Invariants | New restore / schedule-rebuild journals | Must use `AccountingService` cash/fund mirrors; extend `MemberInvariantService` if new reference types appear |

### 3.9 Member UI vs admin

Simulator mid-life partials are **always roll-up**. Live admin already has skip. After alignment, member mid-life can stay roll-up-only (matches golden UI); skip remains the disbursement approach + admin.

---

## 4. Phased implementation

Do not skip Phase 0. Phases 1–2 move money; they need ledger and recon tests before UI polish.

```text
0 kernel → 1 schedule rebuild → 2 restore settle → 3 paid fund floor → 4 apply/settlement modes → 5 grace dates → 6 overlay/docs
```

### Phase 0 — Shared kernel (no production behavior change)

Extract golden math from the simulator / calculator into a shared type (working name `LoanLifecycleMath`) that both can call:

- remaining maturity, months, installment faces (remainder on **first** pending after a payment; last-row balloon only on **initial** build)
- roll-up / skip row mutations
- full restore amount = `pre − current`
- Paid fund floor = `max(fund, top_up)`

Point the simulator at it first. Characterization tests from `MemberLoanLifecycleSimulatorTest` become the contract.

**Verify:** existing simulator tests still pass; no production loan test changes required yet.

### Phase 1 — Schedule rebuild on regular / partial pay

1. Replace “exact EMI only” (`LoanService::recordRepayment`) with apply-toward-maturity (≥ min EMI). Sub-EMI amounts belong on the partial-settlement action (golden allows them there).
2. Rebuild pending `LoanInstallment` amounts so they sum to remaining maturity; drop or skip tail rows per option.
3. **Stop** `ensureScheduleInstallmentAmount()` from forcing the original `fullRepaymentThreshold()` after a shortened horizon.
4. Member “Pay this period” stays one due EMI; add “Pay extra / roll up” that calls the same kernel.
5. Auto-collect still only takes **currently due** rows.

**Verify:** port simulator cases (5000 on 24×2500, 1000 partial, 3×1000, 10000 roll-up) onto real loans with ledger assertions (cash DR, fund CR, master mirrors, no pool drift).

### Phase 2 — Full early settlement = restore

1. Change `LoanEarlySettlementService::earlySettle()` (or a new method used by member and admin) to:
   - required amount = restore delta, not Σ remaining EMIs
   - cancel pending/overdue rows
   - credit member fund + master fund mirror by restore delta
   - status fully settled; eligibility uses restored fund
2. Cash policy: simulator does **not** debit cash. Match that unless product later adds “return unused disbursement.”
3. `LoanCalculatorCurrentLoanSettlement::FULL_EARLY_SETTLEMENT` must see the same projected fund as live after this close.
4. Prefer `member_fund_balance_at_disbursement` as pre-loan fund (same fallback reconstruction as the calculator).

**Verify:** stored pre-loan 5000, fund −5000 → after settle fund 5000; recon clean; `SETTLEMENT_COOLDOWN` uses restored fund vs tier ceiling.

**Product confirm before coding:** member keeps disbursement cash and fund is restored. If that is rejected, change the **simulator** instead of this phase.

### Phase 3 — Paid close

1. When the last EMI is paid (or remaining maturity hits 0 via overpay), `fund = max(fund, top_up)` via an explicit mirrored credit if short.
2. Overpay beyond maturity stays in **cash**.
3. Do not use `isReadyToSettle()` as a silent complete-without-schedule unless admin waiver (`LoanThresholdInstallmentWaiverService` stays admin-only).

**Verify:** exact payoff → fund = top-up, not eligible until contributions (diagram: 10k vs 24k); overpay 65k → fund 10k + cash 5k.

### Phase 4 — Apply + current-loan settlement

1. At apply, reuse calculator blocks (negative projected fund, member portion &gt; fund at start).
2. If the member has an active loan, either:
   - require an explicit settlement mode (the three calculator options) then apply, or
   - keep the hard active-loan gate until they close first  
   Golden is “by start date the current loan is settled.” Pick one path and implement it; do not leave estimate and apply telling different stories.
3. Align `min_fund_balance` with the calculator **or** add it to the estimate UI so estimate and apply match.
4. Tenure / delinquency / membership stay live-only unless later added to the calculator.

**Verify:** a portal path that today only estimates can apply after a matching live settlement.

### Phase 5 — Grace / first due

Drive `activateAfterFullDisbursement()` first EMI from the same inputs as `estimatedSchedule()` (start cycle paid, grace N, `LoanRepaymentWindowPolicy`).

**Verify:** calculator `first_due_date` equals live first installment `due_date` for the same start + grace.

### Phase 6 — Overlay and docs

- Delinquency / late fees / guarantor: unchanged; document as overlay
- Bank clear: unchanged
- Remove or quarantine dead interest helpers (`LoanService::computeMonthlyRepayment` / `computeTotalDue`) so they cannot drift from golden maturity (maturity is master + settlement%, not interest)
- Simulator UI copy can drop “educational, not live” **after** Phases 1–3 are in production
- Update [loan_lifecycle_workflows.md](./loan_lifecycle_workflows.md) settlement sections to match live once Phases 1–3 ship

---

## 5. Test and accounting gates

Every money-moving phase must assert:

- member fund and cash balances
- master cash pool and master fund pool (`MASTER_CASH_POOL_DRIFT` / `MASTER_FUND_POOL_DRIFT`)
- `MEMBER_FUND_DRIFT` / `MEMBER_CASH_DRIFT` (§5.13)
- pending installment amounts sum to remaining maturity
- no extra ledger on bank statement match
- bilingual UI strings for any new member/admin copy (`lang/ar.json` via merge scripts)

Posting order remains: **master leg first**, then member; do **not** put `member_id` on master cash unless there is a documented reason (collection drain).

---

## 6. Non-goals

- Changing `APP_BRAND` or brand packs
- Removing cash disbursement, bank clearing, or master mirrors
- Removing delinquency / default / guarantor flows
- Making the simulator post to the ledger
- Combining loan disbursement with bank payout clearance

---

## 7. Suggested start

Start **Phase 0 + Phase 1** unless Phase 2’s cash-and-restore policy needs a product decision first. Phase 2 is the highest-risk money change; do not fold it into schedule rebuild.
