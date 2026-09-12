# Loan lifecycle simulator — intent vs live rules

What-if on the member **Loan calculator** page (Estimate / Lifecycle simulator toggle).

**Golden reference:** Estimate + Lifecycle simulator. Production should match those economic outcomes and schedule math. Alignment work is specified in [loan-calculator-simulator-production-alignment-plan.md](./loan-calculator-simulator-production-alignment-plan.md). Until those phases land, the simulator UI is **educational what-if**, not a preview of current ledger posting.

Simulator source of truth: lifecycle intent below (the old diagram’s exclusive “Path A / Path B” fork was misleading).  
Authoritative formulas: `MemberLoanLifecycleSimulator` and `MemberLoanCalculatorService` (and their unit tests), not this file if they disagree.

## Modes on one page

| Mode | Purpose |
|---|---|
| **Estimate** | Application-time estimate (funding, grace, projection, EMI schedule, roll-up/skip of excess fund). |
| **Lifecycle simulator** | What-if repayment life from an Estimate snapshot. Side-effect free. Shows an **updating schedule** (cycle, due date, days until due). |

## Lifecycle intent (simulator)

### Shared plan math (same as Estimate)

- Member portion / fund (master) portion from funding strategy
- Maturity amount = fund portion + settlement top-up (`loan × settlement%`)
- Min installment from tier
- Eligibility after close = `% × tier ceiling`

### While Active — one continuous flow

Actions can be **combined** in any order while the loan remains Active:

1. **Regular payments** (≥ min installment)
   - Add to total repaid; remaining = maturity − repaid
   - Recalculate remaining months and expected maturity
   - Rebuild the pending schedule (cycle / due date / amount)

2. **Partial early settlement** (roll-up or skip)
   - Apply a lump toward maturity (may be **below** one EMI)
   - **Roll up:** compress remaining horizon
   - **Skip:** mark covered cycles skipped; calendar length preserved where possible
   - Member mid-life UI always roll-up; skip is a disbursement approach

3. **Full early settlement** (anytime while Active)
   - Not a separate exclusive path
   - Available after zero, some, or many regular / partial payments

```text
Full settlement amount = max(0, pre_loan_fund − current_fund)
```

- Restores simulated fund to **pre-loan fund**
- Does **not** debit simulated cash
- Cancels remaining schedule rows → **Fully settled**

Do **not** add outstanding fund portion on top of `(pre − current)`; that double-counts.

### Normal maturity (Paid)

- When `total_repaid ≥ maturity` → **Paid**
- Member fund ends **≥ top-up** (`fund = max(fund, top_up)`). Exact payoff lands on top-up; overpay surplus is **cash**, not extra fund
- After Paid: resume contributions at **any allowable contribution amount**

### After close

- Eligible (in the simulator) when `fund ≥ eligibility amount`
- Otherwise apply contribution cycles at any allowable amount until the gate is met

## Live production today (not what the simulator posts)

| Topic | Lifecycle intent / simulator | Live today |
|---|---|---|
| Mid-life overpay | Recalculate remaining maturity + rebuild schedule | Fixed installment schedule; ordinary collection does not rebuild horizon; `recordRepayment` requires exact EMI |
| Partial early settle | Sub-EMI allowed; remaining faces redistributed | Explicit partial early settle (≥ one EMI) from **cash**; remaining faces may be rewritten to original threshold |
| Full early settle | Restore `pre − current` → pre-loan fund; no cash debit of EMI stack | Pay remaining EMIs (+ late fees) from **cash** → `early_settled`; fund rises only by those EMI principals |
| Normal Paid fund | `fund = max(fund, top_up)` | Repayments debit **cash** and credit fund per paid EMI; `completed` does not snap fund to top-up |
| Post-loan contribution | Any allowable amount | Exemption ends; member’s existing allowable amount resumes |
| Eligibility after close | Fund ≥ `% × tier ceiling` | Same idea via settlement cooldown / `eligibilityThresholdAmount()` |

## Production alignment

See [loan-calculator-simulator-production-alignment-plan.md](./loan-calculator-simulator-production-alignment-plan.md).

Until Phases 1–3 of that plan ship, keep the simulator labelled as educational what-if.
