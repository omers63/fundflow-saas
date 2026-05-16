# Transaction reversal — “No shared source” explained

**“Reverse all related entries”** only works when several ledger lines were created from the **same business event** (same `reference` in the database). The helper text **“No shared source”** (now split into clearer messages) means **this row is not linked that way**, so only the single line you opened can be reversed.

## When you see it

| Situation | What it means |
|-----------|----------------|
| **Manual Credit / Debit** | Header actions post with **no** reference — standalone adjustment. |
| **Refund** | Master + member cash debits with **no** reference. |
| **Reversal line** | Reference points at **another transaction** (`Transaction #123`), not a Loan/Contribution. “Reverse all” is for unwinding a **loan disbursement**, not chaining reversals. |
| **Loan / contribution / repayment / bank mirror** | Should **not** show “no linked source”. You should see something like “Also reverses 2 other ledger line(s) tied to the same Loan #5…”. |

## How to check a row

Open **View** on the transaction and look at **Reference**:

- **—** (empty) → manual/unlinked → “No linked source…”
- **Reversal of #…** or reference is another transaction → reversal entry
- **Loan #5**, **Contribution #12**, **BankTransaction #…** → shared source; toggle should describe related lines

## Example

**Loan disbursement** creates **3** lines (master fund debit, member fund debit, member cash credit), all with `reference` = that **Loan**. Reversing **any one** of those with the toggle on should reverse **all three**.

**Manual credit** on an account creates **one** line with `reference` = null → only that line can be reversed.

---

Helper text in the Reverse modal is now more specific (manual vs reversal vs multi-line source) so the reason is obvious on the row you’re on. If you expect a loan/contribution link but still see “no linked source”, note which transaction (description + account) and trace why `reference` was not stored.

## Technical criteria (`canUseFullSourceReversal`)

Full-source reversal is available when:

- `reference_type` is set
- `reference_id` is set
- `reference_type` is **not** `Transaction::class` (i.e. not a reversal counter-entry)

Related ledger lines are found with:

```sql
WHERE reference_type = ? AND reference_id = ?
```

across **all accounts** (master fund, member fund, member cash, master cash, etc.).
