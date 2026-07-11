# Contribution Exemption Policy - Session Outcomes and Implementation Plan

**Status:** Confirmed - D1-D16 proposed defaults approved and implemented (2026-07-11)  
**Last updated:** 2026-07-11 (policy implementation complete)  
**Context:** Samman tenant (`cycle_start_day = 6`); Nov 2025 collection cycle investigation

### Implementation snapshot (2026-07-11)

| Area | Location |
|------|----------|
| Single source of truth | `App\Support\ContributionExemptionPolicy` |
| To Collect filter (D6) | `ContributionCycleService::pendingMembersQueryForPeriod()` - PHP policy filter |
| Member exemption API | `Member::isExemptFromContributions()` delegates to policy |
| Export / summary (D2) | `ContributionCollectionSummaryState` - posted before grace label |
| Delinquency preload | `MemberDelinquencyEvaluator::preloadExemptionLoans()` |
| Guarantor transfer (D13-D16) | `LoanGuarantorTransferService`, threshold waiver guard |
| Grace shift at disbursement | `Loan::memberHasContributionForCycleAsOf()` uses COALESCE(paid_at, posted_at, created_at) |
| Tests | `ContributionExemptionPolicyTest`, `ContributionCycleLoanExemptionTest`, pending members, guarantor transfer, insights |
| Architecture guard | `tests/Architecture/ContributionExemptionArchitectureTest.php` — no legacy SQL exemption scopes |
| Test hygiene | `InitializesTenancy` resets tenant business-day override; import cut-off triggers arrear collection after opening balance post |

### Follow-ups completed (2026-07-11)

- Removed unused `Member::scopeNotExemptFromContributionsForCycle()` (superseded by policy PHP filter).
- Architecture test blocks reintroduction of SQL exemption scopes.
- `Contribution::creating` guard blocks EMI-phase rows only (grace voluntary contributions allowed).
- `MemberOpeningBalanceService` triggers cash collection after `IMPORT_CUTOFF` posting (arrears only; current in-window cycle still skipped by collection rules).
- Broader regression suite: 121 tests across contribution, exemption, reconciliation, and loan repayment paths.
- Testing tenant migration verified: `contribution_cycle_allocations` table dropped.

---

## 1. Conversation summary (recent prompts)

This document captures outcomes from the Jul 9-10, 2026 session on contribution collection,
exemption logic, and related fixes.

### 1.1 Earlier session context (same thread)

| Prompt | Outcome |
|--------|---------|
| Why is Collected empty for Nov cycle? | Investigated collection/export state; led into allocation and exemption behaviour. |
| Remove `contribution_cycle_allocations` | User confirmed bulk spread is not desired. Feature removed; performance optimisations kept. |
| PHPStan: `pendingMembersQueryForPeriod()` return type | Fixed closures to return the builder; added Builder types on scopes. |
| 30 skipped on Run contribution cycle (Nov 2025) | Skipped = `isExemptFromContributions(11, 2025)` (mostly active EMI loans). 45 insufficient, 0 applied. |
| Off-by-one cycle (member #29) | Nov cycle = window 6 Nov - 5 Dec. Member #29 settled 3 Nov (Oct cycle) - must not be skipped for Nov. |
| Proceed with cycle-boundary fix | Partial fix: `loanRepaymentOverlapsContributionCycle()` + tests. |

### 1.2 Last seven prompts (authoritative rule refinement)

| # | User prompt (summary) | Outcome |
|---|----------------------|---------|
| 1 | Clarify exemption: exempt iff EMI repayment cycle OR grace cycle | Deviation audit delivered; no code changes. |
| 2 | Grace only postpones EMI (contributions still due during grace - superseded) | Reassessed; requested single source of truth. |
| 3 | Grace postpones EMI and exempts N contribution periods | Final grace model captured. Re-audit G1-G11. |
| 4 | Draft full plan for all reported gaps | Plan delivered in chat. |
| 5 | Early full/partial loan settlement? | Addendum in Section 6. |
| 6 | Document outcomes in .md | This file. |
| 7 | Guarantor transfer EMI + settlement threshold | Fund remainder only on transfer; no threshold EMI. See Section 7. |

---

## 2. Authoritative business rules (target state)

### 2.1 Labelled contribution cycles

A labelled cycle `(month, year)` - e.g. Nov 2025 - is **not** the calendar month. It is the window:

```text
[cycleStartAt(month, year), cycleDueEndAt(month, year)]
```

Driven by tenant setting `contribution.cycle_start_day` (Samman: **6**).

**Example (start day 6):**

| Label | Window |
|-------|--------|
| Oct 2025 | 6 Oct 2025 - 5 Nov 2025 |
| Nov 2025 | 6 Nov 2025 - 5 Dec 2025 |

### 2.2 When is an active member exempt from contributions?

A member is **contribution-exempt** for labelled cycle `(m, y)` if **either**:

#### A. Grace period (`grace_cycles = N` on the loan)

- Grace **postpones first EMI** by **N labelled contribution cycles**.
- Grace **also exempts** the member from **required** contributions for exactly those **N cycles**.
- Grace cycles start from the **disbursement-labelled cycle**, with a cutoff rule:
  - Member **already contributed** for disbursement cycle -> grace starts the **following** cycle.
  - Member **had not contributed** -> grace starts with the **disbursement cycle**.
- During grace: no EMI; contribution not required; no arrears if skipped; voluntary contributions still count.

#### B. EMI repayment period

- From `first_repayment_month` / `first_repayment_year` cycle **start** through loan closure.
- During EMI: EMI is due; contribution not required.
- Closure end date: `settled_at ?? completed_at`, compared using **cycle windows**.

#### Otherwise

Contribution is **required** (join date, membership, arrears cut-off, household rules, etc.).

### 2.3 Gap months

If `first_repayment` begins **after** the grace range ends, intermediate cycles are
**contribution-liable** unless another exemption applies. (Proposed D1: yes, liable.)

### 2.4 Normal loan schedule and settlement threshold

For a **standard (non-transferred) loan**, the EMI schedule repays the **full fund obligation**:

```text
totalRepaymentTarget = master_portion + (amount_approved * settlement_threshold)
installments_count   = ceil(totalRepaymentTarget / min_monthly_installment)
```

- **master_portion** - fund slice repaid to the pool (after member fund used at disbursement).
- **settlement_threshold** - percentage of approved amount (e.g. 5-16%); spread across EMIs
  like the master slice. Final installment absorbs remainder.
- **member_portion** - settled at disbursement; **not** part of EMI schedule.
- **Threshold waiver** - after master_portion fully repaid; normal loans only.

**Reference:** `Loan::fullRepaymentThreshold()`, `LoanLifecycleService::activateAfterFullDisbursement()`.

### 2.5 Guarantor liability transfer (loan + EMI)

When a loan is **transferred to the guarantor** (`LoanGuarantorTransferService::transferToGuarantor`):

| Aspect | Rule |
|--------|------|
| Who holds the loan | `member_id` moves to guarantor; status `transferred`; borrower suspended |
| Guarantor obligation | Remaining **master_portion only**: `max(0, master_portion - repaid_to_master)` |
| Excluded | Member portion (never guarantor liability) |
| Excluded | Settlement threshold - no threshold EMIs on rebuilt schedule |
| Schedule rebuild | Delete pending/overdue; new installments = fund remainder only |
| Threshold waiver | Not applicable |

**Normal vs transfer:**

```text
Normal EMI target:     master_portion + (amount_approved * settlement_threshold)
Guarantor EMI target:  max(0, master_portion - repaid_to_master)
```

### 2.6 Contribution exemption after guarantor transfer

| Party | Exemption behaviour (target) |
|-------|------------------------------|
| Original borrower | EMI/grace exemption ends at `transferred_to_guarantor_at`. Loan off `member_id`. |
| Guarantor | EMI-exempt for cycles overlapping guarantor-rebuilt schedule. No new grace. |
| Policy lookup | Loans queried by `member_id`. After transfer, only guarantor sees open loan. |

---

## 3. Work already completed in codebase

### 3.1 Removed: legacy allocation / bulk spread

**Removed:** `ContributionAllocationService`, `ContributionCycleAllocation` model,
rebuild command + tests, create migration wiring.

**Kept:** Arrears cut-off on To Collect, performance optimisations, drop migration
`database/migrations/tenant/2026_07_10_084700_drop_contribution_cycle_allocations_table.php`.

**Deploy:** `php artisan tenants:migrate` on production if not already applied.

### 3.2 Partial fix: EMI exemption cycle boundaries

**Added:** `ContributionCycleService::loanRepaymentOverlapsContributionCycle()`

| Loan state | Overlap rule |
|------------|--------------|
| Closed (`completed`, `early_settled`) | Exempt if `settled_at ?? completed_at` >= `cycleStartAt(m, y)` |
| Active / transferred | Exempt if disbursement calendar month <= period label (gap G17) |

**Wired to:** `Member::wasInLoanRepaymentCycle()`, SQL scope, `MemberDelinquencyEvaluator`.

**Tests:** `tests/Unit/ContributionCycleLoanExemptionTest.php`.

### 3.3 Guarantor transfer obligation fix (2026-07-10)

- `LoanGuarantorTransferService::remainingGuarantorObligation()` - fund remainder only (no threshold).
- `rebuildGuarantorSchedule()` - uses `Loan::scheduleInstallmentAmount()`; sets `installments_count`.
- `LoanThresholdInstallmentWaiverService` - explicit block for `transferred` loans.
- Tests: `tests/Feature/Tenant/LoanGuarantorTransferTest.php`.

### 3.4 Not yet implemented

- `ContributionExemptionPolicy` (single source of truth)
- Grace range replay from `disbursed_at` + `grace_cycles` + contribution cutoff
- EMI start from `first_repayment` for active loans
- Summary/export voluntary grace treatment

---

## 4. Known deviations (current code vs target rules)

| ID | Issue | Severity |
|----|-------|----------|
| G1 | Grace = all cycles before `first_repayment`, not exactly `grace_cycles` | High |
| G2 | Active EMI exempt from disbursement month, not `first_repayment` | High |
| G3 | Completed EMI end - partially fixed for closed loans | Medium |
| G4 | No grace range replay from disbursement + grace_cycles | High |
| G5 | Inconsistent cycle boundaries vs calendar labels | Medium |
| G6 | Gap months may be wrongly exempt | Medium |
| G7 | Voluntary grace: summary shows grace-exempt not paid | Low-Medium |
| G8 | Period-less exempt: any active loan with pending installments | Medium |
| G9-G11 | Duplicated logic across Member, SQL, delinquency, summary | Maintenance |
| G22 | `remainingGuarantorObligation()` added threshold on transfer | **Fixed** (Section 3.3) |
| G23 | Guarantor rebuild may not update `installments_count` | **Fixed** (Section 3.3) |
| G24 | No tests for guarantor schedule = fund remainder only | **Fixed** (Section 3.3) |
| G25 | No borrower vs guarantor EMI window distinction | Medium |
| G26 | Threshold waiver not guarded for transferred loans | **Fixed** (Section 3.3) |

### 4.1 Member #29 case (validated)

| Fact | Value |
|------|-------|
| Loan `completed_at` | 2025-11-03 |
| Nov cycle (start day 6) | 2025-11-06 - 2025-12-05 |
| Before fix | Wrongly exempt for Nov |
| After partial fix | Not exempt for Nov; exempt for Oct cycle |

### 4.2 Guarantor transfer code deviation (resolved)

Previously `remainingGuarantorObligation()` incorrectly included `amount_approved * settlement_threshold`.
Fixed to `max(0, master_portion - repaid_to_master)` only.

---

## 5. Implementation plan

### Phase 0 - User sign-off

Confirm decisions D1-D16 (Section 8).

### Phase 1 - ContributionExemptionPolicy

Create `App\Support\ContributionExemptionPolicy` as single source of truth.

**Proposed API:**

| Method | Purpose |
|--------|---------|
| `graceCycleLabels(Loan $loan)` | Grace-exempt cycle labels |
| `isInGraceCycle(Member, m, y)` | Grace check |
| `emiStartAt(Loan $loan)` | First repayment cycle start |
| `repaymentEndedAt(Loan $loan)` | `settled_at ?? completed_at` |
| `isLoanInEmiRepaymentPhase(Loan, m, y)` | Cycle-window overlap |
| `isContributionExemptForCycle(Member, m, y)` | Grace OR EMI |
| `guarantorEmiStartAt(Loan $loan)` | First cycle after guarantor rebuild |

### Phase 2 - Wire consumers

Member, To Collect, run cycle, delinquency, summary/export, guarantor transfer service,
threshold waiver guard.

### Phase 3 - Tests

Policy unit tests, loan exemption tests, pending members tests, guarantor transfer tests.

### Phase 4 - Optional schema

`grace_start_month` / `grace_start_year` on loans if PHP filter too slow.

### Phase 5 - Verification

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Unit/ContributionCycleLoanExemptionTest.php
php artisan test --compact tests/Feature/Tenant/ContributionCyclePendingMembersTest.php
```

---

## 6. Early full and partial loan settlement

| Path | Status after | settled_at | Service |
|------|--------------|------------|---------|
| Full early settlement | `early_settled` | Set | `LoanEarlySettlementService::earlySettle()` |
| Partial early settlement | `active` | Not set | `partialEarlySettle()` |
| Natural payoff | `completed` | Max paid_at | `syncPaidOffStatusFromInstallments()` |
| Threshold waiver | `completed` | Waiver time | `LoanThresholdInstallmentWaiverService` |

**Key rules:**

- Full early settle before cycle window -> not exempt that cycle.
- Partial settle while active -> exempt until full close (D9).
- `early_settled` and `completed` use same end rule (D10).

See gaps G15-G21 in earlier audit for settlement-specific items.

---

## 7. Guarantor transfer and settlement threshold (detail)

### 7.1 Two delinquency paths

| Path | What happens | Schedule impact |
|------|--------------|-----------------|
| Guarantor debit after grace | Guarantor fund pays installment | Borrower schedule unchanged |
| Full transfer to guarantor | `member_id` -> guarantor; schedule rebuilt | Fund remainder only |

### 7.2 Normal threshold example

Approved 50,000; master_portion 25,000; settlement_threshold 5%:

```text
totalRepaymentTarget = 25,000 + 2,500 = 27,500
```

Threshold is part of the same EMI schedule as the fund slice.

### 7.3 Guarantor obligation on transfer

```text
guarantorObligation = max(0, master_portion - repaid_to_master)
```

Not included: member_portion, settlement_threshold amount.

### 7.4 Contribution exemption alignment

| Question | Target |
|----------|--------|
| Guarantor EMI-exempt on transferred schedule? | Yes |
| Borrower exempt after transfer? | No |
| Guarantor gets grace? | No |
| Threshold waiver on transferred loan? | No (D15) |

### 7.5 Implementation tasks

1. Fix `remainingGuarantorObligation()` - remove threshold addon.
2. Update `rebuildGuarantorSchedule()` - set `installments_count`.
3. Guard threshold waiver for normal loans only.
4. Extend exemption policy for borrower vs guarantor.
5. Add tests.

---

## 8. Pending decisions (user confirmation required)

| ID | Question | Proposed default |
|----|----------|------------------|
| D1 | Gap months contribution liable? | Yes |
| D2 | Voluntary grace shows as paid in export? | Yes |
| D3 | Period-less exempt = open-cycle grace or EMI only? | Yes |
| D4 | partially_disbursed loans keep exempt? | Keep current |
| D5 | Legacy: replay grace or trust stored fields? | Replay |
| D6 | To Collect: PHP filter v1? | Yes |
| D7 | Early settle during cycle: exempt whole cycle? | Yes if settlement >= cycleStart |
| D8 | Early settle during grace: EMI exempt? | No |
| D9 | Partial settle exempt until? | Full close |
| D10 | early_settled vs completed same end rule? | Yes |
| D11 | After early settle, on To Collect next cycle? | Yes if no overlap |
| D12 | Flag contribution posted after settled_at? | Flag |
| D13 | Guarantor obligation = fund remainder only? | Yes |
| D14 | Borrower exempt after transfer? | No |
| D15 | Threshold waiver on transferred loans? | No |
| D16 | Guarantor grace on rebuilt schedule? | No |

---

## 9. Production context (Samman / Nov 2025)

| Metric | Value |
|--------|-------|
| cycle_start_day | 6 |
| Run cycle Nov 2025 | 30 skipped, 45 insufficient, 0 applied |
| Member #29 | completed 2025-11-03 -> Oct cycle, not Nov after fix |

---

## 10. Key file reference

| Area | Path |
|------|------|
| Cycle windows | `app/Services/ContributionCycleService.php` |
| Member exemption | `app/Models/Tenant/Member.php` |
| Loan grace / EMI setup | `app/Models/Tenant/Loan.php` |
| Early settlement | `app/Services/Loans/LoanEarlySettlementService.php` |
| Guarantor transfer | `app/Services/Loans/LoanGuarantorTransferService.php` |
| Threshold waiver | `app/Services/Loans/LoanThresholdInstallmentWaiverService.php` |
| To Collect | `ContributionCycleService::pendingMembersQueryForPeriod()` |
| Tests | `tests/Unit/ContributionCycleLoanExemptionTest.php` |

---

## 11. Explicit non-goals

- Do not reintroduce `contribution_cycle_allocations` or bulk spread.
- Do not change accounting / master-member mirror rules.
- Full policy implementation blocked until D1-D16 confirmed.
- Guarantor obligation fix can land independently once D13 confirmed.

---

## 12. Next steps

1. User confirms D1-D16 (especially D13-D16).
2. Fix `LoanGuarantorTransferService::remainingGuarantorObligation()` - **done**.
3. Implement ContributionExemptionPolicy + tests.
4. Wire consumers.
5. Run focused test suite + pint.
6. Deploy allocation drop migration if needed.
