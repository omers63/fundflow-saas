# Loan lifecycle simulator — intent vs live rules

Educational what-if on the member **Loan calculator** page (Estimate / Lifecycle simulator toggle).  
Simulator source of truth: lifecycle intent below (the old diagram’s exclusive “Path A / Path B” fork was misleading).  
Production ledger / installment behavior is **not** changed by this feature; alignment is planned later.

## Modes on one page

| Mode | Purpose |
|---|---|
| **Estimate** | Existing application-time estimate (funding, grace, projection, EMI schedule, roll-up/skip of excess fund). Unchanged. |
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
   - Apply a lump sum (≥ one EMI) toward maturity  
   - **Roll up:** compress remaining horizon (like shortening the schedule)  
   - **Skip:** mark covered cycles skipped; calendar length preserved where possible  

3. **Full early settlement** (anytime while Active)  
   - Not a separate exclusive path  
   - Available after zero, some, or many regular / partial payments  

```text
Full settlement amount =
  Outstanding fund portion
  + (Pre-loan fund − Current simulated fund)
```

- Restores simulated fund to **pre-loan fund**  
- Cancels remaining schedule rows → **Fully settled**

### Normal maturity (Paid)

- When `total_repaid ≥ maturity` → **Paid**  
- Member fund ends **≥ top-up** (exact top-up if payoff is exact; higher if overpay)  
- After Paid: resume contributions at **any allowable contribution amount**

### After close

- Eligible (in the simulator) when `fund ≥ eligibility amount`  
- Otherwise apply contribution cycles at any allowable amount until the gate is met

## Live production today (not what the simulator posts)

| Topic | Lifecycle intent / simulator | Live today |
|---|---|---|
| Mid-life overpay | Recalculate remaining maturity + rebuild schedule | Fixed installment schedule; ordinary collection does not rebuild horizon |
| Partial early settle | Simulator roll-up / skip on the what-if schedule | Explicit **partial early settle** (roll-up / skip) on real installments |
| Full early settle | Restore formula → pre-loan fund; allowed after prior payments | Pay remaining EMIs (+ late fees) from **cash** → `early_settled`; no restore-to-pre-loan-fund |
| Normal Paid fund | Fund **≥ top-up** from amounts paid to reach ≥ maturity | Repayments debit **cash**; fund is not set to ≥ top-up on `completed` |
| Post-loan contribution | Any allowable amount | Exemption ends; member’s existing allowable amount resumes |
| Eligibility after close | Fund ≥ `% × tier ceiling` | Same idea via settlement cooldown / `eligibilityThresholdAmount()` |

## Planned production alignment (later)

1. Regular / additional overpay should update real `LoanInstallment` schedules (recalculate remaining + expected maturity).  
2. Full early settlement should follow the restore formula (and fund outcome), and remain available after prior payments / partial settlement.  
3. Normal Paid should ensure fund semantics consistent with **≥ top-up**.  
4. Keep post-loan contribution as any allowable amount.

Until then, the simulator UI states that it is **educational what-if**, not a preview of current ledger posting.
