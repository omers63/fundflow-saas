# FundFlow — Accounting & Ledger Reference

**One page · For accountants and auditors**

---

## Chart of accounts (conceptual)



![Diagram 1](../_assets//var/www/fundflow-saas/docs/fund-flow-dynamics-accountants/diagram-01.png)



| Account | Type | Invariant |
|---------|------|-----------|
| Master Cash | Pool cash | Balance = Σ Member Cash |
| Master Fund | Pool equity | Balance = Σ Member Fund |
| Master Bank | Bank control | Tied to imported `bank_transactions` |
| Member Cash | Liability to member | Spendable balance |
| Member Fund | Member equity in pool | May be negative (active loan) |
| Loan sub-account | Principal tracking | Per active loan |

---

## Posting conventions

### Mirror pairs (same transaction)

| Event | Order | Legs |
|-------|-------|------|
| Cash inflow | Master first | CR master cash → CR member cash |
| Cash outflow | Member first | DR member cash → DR master cash |
| Fund credit | Master first | CR master fund → CR member fund |
| Fund debit | Member first | DR member fund → DR master fund |

**Do not** attach `member_id` to master cash legs unless documented — it triggers auto-collection.

### Bank clearance (non-posting)

Ledger records **intent**; clearance updates `is_cleared` and matches lines. **No additional cash/fund entries** on successful match.

---

## Journal patterns by event

### Contribution collection

| | Account | Dr/Cr |
|---|---------|-------|
| Dr | Member Cash | amount |
| Dr | Master Cash | amount (mirror) |
| Cr | Member Fund | amount |
| Cr | Master Fund | amount (mirror) |

### Loan disbursement

| | Account | Dr/Cr |
|---|---------|-------|
| Dr | Loan account | principal |
| Dr | Member Fund | member + master portions (mirrored on master fund) |
| Cr | Loan account | member portion applied to principal |
| Cr | Member Cash | full disbursement |
| Cr | Master Cash | mirror |

### Cash-out approval

| | Account | Dr/Cr |
|---|---------|-------|
| Dr | Member Cash | amount |
| Dr | Master Cash | mirror |
| — | Uncleared bank transaction | negative placeholder |

### Loan repayment (with cash flow)

| | Account | Dr/Cr |
|---|---------|-------|
| Cr | Member Cash | amount (cash-in mirror) |
| Cr | Master Cash | mirror |
| Dr | Member Cash | amount (collection) |
| Dr | Master Cash | mirror |
| Cr | Member Fund | amount |
| Cr | Master Fund | mirror |
| Cr | Loan sub-account | principal |

Net effect on cash: **zero** (paired mirror); fund and loan reflect repayment.

---

## Repayment economics

```
Repayment obligation = master_portion + (amount_approved × settlement_threshold)
```

- Member’s own fund portion at disbursement = **equity**, not collected via EMI.
- **100% member-funded** loan (`master_portion = 0`, `settlement_threshold = 0`): no EMI schedule; completed at import.

---

## Reconciliation checks

| Code | Formula / rule |
|------|----------------|
| `MASTER_CASH_POOL_DRIFT` | `master_cash.balance ≠ Σ member_cash.balance` |
| `MASTER_FUND_POOL_DRIFT` | `master_fund.balance ≠ Σ member_fund.balance` |
| `MEMBER_CASH_DRIFT` | Component sum vs `accounts.balance` (§5.13) |
| `MEMBER_FUND_DRIFT` | Component sum vs `accounts.balance` (§5.13) |

**Repair:** `php artisan accounting:rebuild-balances --tenants=<tenant>` when stored balance ≠ transaction net.

---

## Legacy migration notes

| Pattern | Risk |
|---------|------|
| Contribution repair + raw `DELETE` on transactions | Cash balance drift — use `deleteTransaction()` |
| Bulk repayment import | Credit/debit pairs; rebuild balances after import |
| Implicit member-funded loans | Complete when no pool/settlement obligation |

---

## Audit trail

| Layer | Source |
|-------|--------|
| Ledger | `transactions` (type, amount, `balance_after`, reference) |
| Bank | `bank_transactions` + import `bank_statements` |
| Workflow | Contributions, loans, cash-outs, fund postings |
| Exceptions | `reconciliation_exceptions` (nightly batch) |

---

## Slide outline (presentation)

1. **Title** — FundFlow ledger architecture  
2. **Accounts** — Master vs member; pool invariants  
3. **Mirrors** — Posting order and pairing rules  
4. **Journals** — Contribution, loan, cash-out, repayment  
5. **Bank** — Intent vs clearance  
6. **Repayment math** — Master slice + settlement  
7. **Reconciliation** — Drift codes and repair  
8. **Migration** — Known integrity patterns  

---

*Full diagrams and sequences: [fund-flow-dynamics.md](./fund-flow-dynamics.md)*
