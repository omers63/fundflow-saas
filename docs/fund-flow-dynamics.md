# FundFlow — Fund Flow Dynamics

This diagram set explains how money moves through FundFlow: **two parallel pools** (cash and fund), kept in sync between master and member accounts, with **bank clearance** as a separate reconciliation step.

---

## 1. Account map — where money lives

```mermaid
flowchart TB
    subgraph external["Outside the system"]
        BANK["Member's external bank account"]
    end

    subgraph master["Master pool (whole fund)"]
        MB["Master Bank<br/><i>imported statement lines</i>"]
        MC["Master Cash<br/><i>cash on hand</i>"]
        MF["Master Fund<br/><i>pool value</i>"]
    end

    subgraph member["Per member"]
        mC["Member Cash<br/><i>spendable balance</i>"]
        mF["Member Fund<br/><i>equity in pool</i>"]
        LA["Loan sub-account<br/><i>principal owed</i>"]
    end

    BANK <-->|"wire / transfer"| MB
    MB <-->|"mirror to cash"| MC
    MC <-->|"always mirrored"| mC
    MF <-->|"always mirrored"| mF

    style MC fill:#e8f4fc
    style MF fill:#e8fce8
    style mC fill:#e8f4fc
    style mF fill:#e8fce8
```

**Pool rules (checked nightly):**

| Invariant | Meaning |
|-----------|---------|
| Master Cash = Σ Member Cash | Total cash in the pool equals the sum of all members’ cash |
| Master Fund = Σ Member Fund | Total fund value equals the sum of all members’ fund balances |

Cash and fund are **separate ledgers**. A member can have cash without fund movement (and vice versa) until an operation links them (e.g. contribution collection moves cash → fund).

---

## 2. How money enters the system

Two main paths; both end with **member cash credited** (and master cash mirrored).

```mermaid
flowchart LR
    subgraph pathA["Path A — Member deposit (Fund Posting)"]
        A1["Member submits deposit"] --> A2["Admin accepts"]
        A2 --> A3["CR Master Cash + CR Member Cash"]
        A3 --> A4["Uncleared bank line created"]
        A4 --> A5["Later: match to imported CSV"]
    end

    subgraph pathB["Path B — Bank statement first"]
        B1["Import CSV → Master Bank"] --> B2["Mirror to Master Cash"]
        B2 --> B3["Post to member → Member Cash"]
        B3 --> B4["Auto-collection may run"]
    end

    EXT["External bank"] --> pathA
    EXT --> pathB
```

**Important:** Ledger posting records **intent** (money is in the member’s cash account). **Bank clearance** (matching an imported line or an uncleared placeholder) confirms it against the real bank — it does **not** post extra cash/fund legs when matched correctly.

---

## 3. Monthly contribution collection

When a member has cash (from a deposit or legacy import mirror), the collection cycle **moves cash into fund equity**.

```mermaid
flowchart TD
    START["Member has cash balance"] --> TRIGGER["Cycle opens / deposit arrives /<br/>onMemberCashIncreased"]
    TRIGGER --> CHECK{"Cash ≥ contribution due?"}
    CHECK -->|Yes| FULL["Collect full amount"]
    CHECK -->|Partial| PART["Collect available;<br/>remainder stays PENDING/OVERDUE"]
    CHECK -->|No| WAIT["Wait for more cash"]

    FULL --> JE["Journal (paired mirrors)"]
    PART --> JE

    JE --> DR1["DR Member Cash"]
    DR1 --> DR2["DR Master Cash <i>(mirror)</i>"]
    DR2 --> CR1["CR Member Fund"]
    CR1 --> CR2["CR Master Fund <i>(mirror)</i>"]

    CR2 --> DONE["Contribution COLLECTED<br/>Member fund balance ↑"]
```

**Economic meaning:** Cash leaves the member’s “wallet” and becomes **fund equity** (their share of the pool). Late fees, if any, also debit member + master cash.

---

## 4. Loan lifecycle — disbursement, cash-out, repayment

### 4a. Disbursement (ledger only — no bank payout yet)

```mermaid
flowchart TD
    APP["Loan approved & disbursed"] --> SPLIT{"Funding split"}

    SPLIT --> MP["Member portion<br/><i>from member's fund equity</i>"]
    SPLIT --> MAST["Master portion<br/><i>from pool</i>"]

    MP --> F1["DR Member Fund + DR Master Fund<br/><i>(mirror)</i>"]
    MAST --> F2["DR Member Fund + DR Master Fund<br/><i>(mirror)</i>"]

    F1 --> LOAN["Loan account: DR principal"]
    F2 --> LOAN

    LOAN --> CASH["CR Member Cash + CR Master Cash<br/><i>(cash payout mirror)</i>"]

    CASH --> POOL["Cash now sits in member account<br/><i>not yet sent to their bank</i>"]
```

### 4b. Cash-out (actual bank transfer)

```mermaid
flowchart LR
    REQ["Member requests cash-out"] --> ACC["Admin accepts"]
    ACC --> DR["DR Member Cash + DR Master Cash"]
    DR --> UNC["Uncleared bank transaction<br/><i>intent to pay out</i>"]
    UNC --> MATCH["Later: Clear/Match against<br/>imported bank debit line"]
```

### 4c. Loan repayment (EMI / imported payment)

```mermaid
flowchart TD
    PAY["Payment received<br/><i>deposit or legacy import</i>"] --> MIRROR["CR Member Cash + CR Master Cash<br/><i>(cash-in mirror)</i>"]
    MIRROR --> COLLECT["DR Member Cash + DR Master Cash<br/><i>(repayment collection)</i>"]
    COLLECT --> FUND["CR Member Fund + CR Master Fund<br/><i>(mirror)</i>"]
    FUND --> LOANCR["CR Loan sub-account<br/><i>principal reduced</i>"]
    LOANCR --> CHECK{"Master portion + settlement<br/>threshold repaid?"}
    CHECK -->|Yes| COMPLETE["Loan completed;<br/>guarantor may be released"]
    CHECK -->|No| ACTIVE["Loan stays active"]
```

**Repayment target:** Master fund slice + settlement threshold (e.g. 16%). The member’s own fund portion at disbursement is **equity**, not cash EMI — only the pool’s share (+ settlement) is collected via repayments.

---

## 5. Special case — 100% member-fund loan (e.g. loan #143)

When the full loan is funded from the member’s own fund (`member_portion` = full amount, `master_portion` = 0, no settlement due):

```mermaid
flowchart LR
    D["Disbursement"] --> FUND["Member fund debited;<br/>principal applied on loan account"]
    FUND --> CASH["Full amount credited to member cash"]
    CASH --> OUT["Member cashes out"]
    OUT --> DONE["No EMI schedule —<br/>loan marked completed<br/><i>nothing owed back to pool</i>"]
```

There is **no pool exposure**, so no ongoing installment obligation.

---

## 6. End-to-end lifecycle (one member, simplified)

```mermaid
sequenceDiagram
    participant Ext as External bank
    participant MB as Master Bank
    participant MC as Master Cash
    participant mC as Member Cash
    participant mF as Member Fund
    participant MF as Master Fund
    participant Loan as Loan account

    Note over Ext,MF: MONEY IN
    Ext->>MB: Transfer in
    MB->>MC: Mirror to cash
    MC->>mC: Post / accept deposit (mirrored)

    Note over mC,MF: CONTRIBUTION
    mC->>mC: Debit (collection)
    MC->>MC: Debit (mirror)
    mF->>mF: Credit
    MF->>MF: Credit (mirror)

    Note over mF,Loan: LOAN DISBURSE
    mF->>mF: Debit (member + master portions)
    MF->>MF: Debit (mirror)
    Loan->>Loan: Debit principal
    MC->>mC: Credit payout (mirror)

    Note over mC,Ext: CASH OUT
    mC->>mC: Debit
    MC->>MC: Debit (mirror)
    mC-->>Ext: Bank transfer (cleared later)

    Note over mC,Loan: REPAYMENT
    MC->>mC: Credit (mirror in)
    mC->>mC: Debit (EMI)
    MC->>MC: Debit (mirror)
    mF->>mF: Credit
    MF->>MF: Credit (mirror)
    Loan->>Loan: Credit principal
```

---

## 7. Mental model for stakeholders

| Question | Answer |
|----------|--------|
| Where is “real” bank money? | **Master Bank** (imported statements) + uncleared lines until matched |
| What can a member spend? | **Member Cash** (after collection hasn’t taken it for contributions/EMIs) |
| What is their stake in the pool? | **Member Fund** (contributions − disbursements + repayments) |
| When does the pool shrink/grow? | Fund legs on contribution (in), disbursement (out), repayment (in) |
| Why two steps for bank? | **Ledger** = economic event; **Clearance** = proof it hit the bank |

---

## 8. Operational functions → account touchpoints

| Function | Cash legs | Fund legs | Bank |
|----------|-----------|-----------|------|
| Deposit accept | CR member + master cash | — | Uncleared line → match |
| Bank import | Mirror → post to member | — | Master bank updated |
| Contribution | DR member + master cash | CR member + master fund | — |
| Loan disburse | CR member + master cash | DR member + master fund | — |
| Cash-out approve | DR member + master cash | — | Uncleared → match |
| Loan repayment | CR then DR cash (mirror pair) | CR member + master fund | — |
| Subscription fee | DR member + master cash | Fee to master fees | — |
| Legacy import | Same mirror patterns | Same mirror patterns | Often skipped |
