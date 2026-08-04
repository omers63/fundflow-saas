## Report only (no code changes)

Verified against `MasterAccountInvariantService`, fund-flow rules, `MembershipSubscriptionFeeService`, `MasterExpenseDisbursementService`, guarantor top-up in `LoanLedgerService`, and live **samman** pool numbers.

---

### 1. How recon currently treats expense vs fees (drift)

**Master fund family (already an “expense = fund extension” idea):**

```
master_fund_pool =
  master_fund
  − invest returns credited to fund
  + invest reserve-funding credits
  + expense reserve-funding credits

fund_delta = |master_fund_pool − Σ member fund|
```

So parking money in **master expense** via fund→expense reserve funding **stays inside fund drift**. Expense disbursement (drawing the expense bucket) is **not** a member-pool event.

**Master cash family (fees are *not* in cash drift today):**

```
cash_delta = |master_cash − Σ member cash|
```

`master_fees` is reported on the control strip only. Recon notes treat fees (with bank/suspense) as **control/reserve**, not a member-mirror leg.

**samman (live):**

| Metric | Value |
|--------|------:|
| cash_delta | 0 |
| fund_delta | 0 |
| master_fees | 13,050 |
| master_expense balance | 0 |
| expense_from_fund_credits | 11,500 |
| fund_pool = member fund | balanced |

Pool drift is already green. Adding `master_fees` into cash as  
`master_cash + fees` vs `Σ member cash` would invent a **13,050 cash drift** on samman even though cash↔member mirrors are perfect—that would be **creating** noise, not unmasking cash-pool bugs.

---

### 2. Flow-by-flow vs your rules + fund-flow policy

#### A) Subscription / application fees (bank-clearing fees)

**Your rule:** bank → master/member **cash** (needs clearing), then cash → **master fees**.

**Implemented** (`MembershipSubscriptionFeeService::postSubscriptionFeeLedger`):

1. **CR member cash + CR master cash** (deposit / transfer mirror), amount = fee transfer  
2. **Uncleared bank line** for that amount (ops row; bank posts on match)  
3. **DR member cash + DR master cash** for settled fee  
4. **CR master fees** for settled fee  

Matches:

- Master/member **cash** dual legs (policy § cash mirror)  
- Fee is **not** bank leg for the fees account itself—only the **transfer** is  
- Separate integrity checks: `membership_application_fee_integrity` / bank posting integrity  

**Global-trial grouping** under `MembershipApplication` is one-sided relative to fees (fees CR without a pairing under the same group once cash is dual-sided). Treating that as an **expected lifecycle shape** is **not** the same as ignoring `cash_delta` / fee integrity.

#### B) Expense disbursement

**Your rule:** DR expense (fund extension) → **CR master cash** → bank remittance + clearing.

**Implemented** (`MasterExpenseDisbursementService::disburse`):

1. **DR master expense only** “(expense out)”  
2. Uncleared synthetic bank line (match-only on clear; **no** master cash / master bank ledger on accept)  

**Reserve funding into expense** (earlier step, `AccountingService` fund→reserve):

- DR master fund `(master fund transfer)`  
- CR master expense `(reserve funding)`  
- Often **null reference** by design  

So:

| Step | Fund-flow / policy | Your description | Code today |
|------|--------------------|------------------|------------|
| Fund → expense reserve | Fund family parking | Expense extension of fund | Yes |
| Expense out + bank | Not “no cash ledger” in docs for cash-out style | DR expense, **CR cash**, then clear | **No — no cash credit** |
| Bank clear | Bank separate from intent | Clearing required | Yes (match/clear ops) |

**Gap:** expense out is **not** cash-out shaped (expense→cash→bank). It is **expense-only debit + bank match/clear**. That also drives global-trial “one-sided expense group” + null-ref reserve pairs (which we classified as expected journal shape, not pool drift).

If product intent is truly **DR expense + CR master cash + uncleared remittance**, that is a **ledger-product change**, not a recon formula tweak. Current path and tests (e.g. match-only expense integrity) encode the existing design.

#### C) Guarantor collection / top-up

**Your rule:**  
DR guarantor fund (+ master fund) → CR borrower cash (+ master cash) → then repayment: DR cash → CR fund (+ loan).

**Implemented** (top-up then installment repayment under `LoanInstallment`):

1. `debitMemberFundWithMasterMirror` on **guarantor**  
2. `creditMemberCashWithMasterMirror` on **borrower**  
3. Collection: DR borrower (and master) cash; CR fund (and master) + loan credit  

Matches fund-flow dual mirrors and does **not** require a bank line for the internal guarantor/borrower fund↔cash bridge. Global-trial installment groups can be one-sided relative to loan liability credit; that is lifecycle shape, not pool-masking.

---

### 3. Are we “masking problems”?

| Layer | What was softened | What still fails if wrong |
|--------|-------------------|---------------------------|
| Global trial (credits vs debits) | Expected one-sided lifecycle / reserve null-refs | True unbalanced **unexpected** journals still warn; **delta** of same-direction pool/bank posting still **displayed** |
| Bank posting integrity | Fee ops rows without master cash (by design) | Matched CSV still needs master **bank** |
| **MASTER_CASH / FUND pool drift** | **Not** diluted by those global-trial exemptions | Samman still uses pure cash mirror and fund_pool formula; **balanced: true** on live data |
| Fee income | Separate `FEE_INCOME_DRIFT` / application fee integrity | Still exists alongside pool check |

So: global trial filters ≠ silencing cash/fund **pool** drift.

---

### 4. Mapping to your two “extension” principles

| Principle | Meaning in current recon | Aligned? |
|-----------|--------------------------|----------|
| **Expense extends master fund** | Fund pool **adds back** expense (and invest) **reserve-funding** so parking fund into expense does not create `MASTER_FUND_POOL_DRIFT` | **Yes** (funding credits only; spent residual is master expense balance / ops bank, not member fund) |
| **Fees extends master cash** | Mirror remains **master cash = Σ member cash** after dual fee legs; fees is master-only sink; **not** folded into cash_delta | **Partially conceptually, not formulaically** |

If fees were folded as:

`cash_control = master_cash + master_fees` vs `Σ member cash`  
→ samman becomes **unbalanced by fees balance (13,050)** even though cash mirrors are perfect. That would mask nothing and would fire false cash drift forever for every settled fee.

A coherent “fees extension” formula would need a **different identity** (e.g. track bank-in / fee-out control, or fees vs declared income), not raw add-to-cash vs members.

---

### 5. Recommendations (for a later implement pass)

1. **Do not** add `master_fees` into `cash_delta` without a new paired identity—avoids false criticals.  
2. **Keep** expense-in-fund-pool via reserve-funding (already correct for your fund extension idea).  
3. **Subscription fee path:** no change needed for policy; keep fee integrity + bank clearance.  
4. **Guarantor path:** no change needed.  
5. **Expense out + CR cash:** only if you intentionally replace current match-only design—that is a **posting** change (`MasterExpenseDisbursementService` + outbound/bank clear + tests), then recon exemptions can follow.  
6. Optional clarity only: recon UI label for “fund pool (fund + parked invest/expense reserves)” vs “fees (cash-origin control, not member mirror)”—docs/copy, not softer severity.

---

### Bottom line

- Your **subscription fee** and **guarantor** descriptions match **fund-flow rules** and the code.  
- **Expense as fund parking** matches recon **fund_pool**.  
- **Expense as cash-credit + bank remittance** does **not** match today’s expense service.  
- **Fees as cash-extension for pool drift** is **not** how `cash_delta` is defined, and folding fees into that comparison would **break** a currently healthy cash mirror on samman rather than reveal a hidden one.  

I have **not implemented** anything per “report before implementation.” If you want a concrete next step, pick one:

1. **Product change:** expense out → DR expense + CR cash + bank clear (real ledger redesign), or  
2. **Recon only:** redefined cash/fund control identities/labels without changing postings, or  
3. **Neither for drift**—keep pool mirrors as-is; only document the extension semantics.
