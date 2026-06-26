# FundFlow Member Portal — User Manual

This manual explains how members use the **member portal** to view balances, pay contributions, manage loans, request deposits and cash-outs, and communicate with fund administrators.

**Portal URL:** `https://<your-fund-domain>/member`  
**Sign in:** Use the email and password (or household login) provided when you joined.

For a short navigation map, see also [member-portal.md](member-portal.md).

---

## Table of contents

1. [Getting started](#1-getting-started)
2. [Understanding your accounts](#2-understanding-your-accounts)
3. [Dashboard overview](#3-dashboard-overview)
4. [Cash account](#4-cash-account)
5. [Fund account](#5-fund-account)
6. [Contributions](#6-contributions)
7. [Loans](#7-loans)
8. [Deposits](#8-deposits)
9. [Cash out](#9-cash-out)
10. [Statements and activity](#10-statements-and-activity)
11. [Settings and profile](#11-settings-and-profile)
12. [Help, messages, and requests](#12-help-messages-and-requests)
13. [Household and dependents](#13-household-and-dependents)
14. [What you cannot do in the portal](#14-what-you-cannot-do-in-the-portal)
15. [Common questions](#15-common-questions)

---

## 1. Getting started

### 1.1 Signing in

1. Open `/member/login`.
2. Enter your email and password.
3. If your household uses **profile selection**, choose your profile and enter your PIN or password when prompted.

### 1.2 Who can use the portal

You must have a **member profile** linked to your login. The portal is for members only — fund administrators use a separate admin area.

### 1.3 If you cannot sign in

Your access may be restricted when your membership status is **inactive**, **delinquent**, **suspended**, **withdrawn**, or **terminated**. The login screen will show a message. Contact the fund office if you believe this is an error.

### 1.4 Language

Use the **language switch** in the top bar to change between English and Arabic where supported.

---

## 2. Understanding your accounts

FundFlow keeps two balances for each member:

| Account | What it is | Typical uses |
|---------|------------|--------------|
| **Cash** | Money available in your member wallet | Deposits, contributions, loan repayments, cash-out |
| **Fund** | Your equity share in the pool | Loan eligibility, funding split on new loans |

**Important distinctions:**

- **Cash out** draws from **cash**, not fund.
- **Loan maximum** is usually based on your **fund balance** (× a borrow multiplier set by the fund).
- **Contributions** move money from cash → fund when collected.
- **Loan disbursement** credits your cash (after debiting your fund share).

On the cash account page you may see:

- **Available to withdraw** — cash you can request for payout.
- **Reserved for next EMI** — cash set aside for an upcoming loan installment so you do not accidentally spend it.

---

## 3. Dashboard overview

**Menu:** Overview (home) → `/member`

The dashboard shows:

- Greeting and any fund notices
- Key figures: cash, fund, loan outstanding, contribution status
- **Quick actions**, for example:
  - Submit a deposit
  - Apply for a loan (if eligible)
  - Open statements
  - Go to cash or fund account
  - Open messages

Use the dashboard as your starting point each month to check whether your **contribution for the open cycle** is posted.

---

## 4. Cash account

**Menu:** My Accounts → **Cash account** → `/member/cash-account`

### What you can do

| Action | Description |
|--------|-------------|
| View balance | Current cash and available amounts |
| View ledger | Recent cash movements |
| **Submit deposit** | Request a bank transfer to the fund (admin review required) |
| **Request cash out** | Shortcut to the cash-out request form |
| View deposit history | Status of past deposit requests |

### Submitting a deposit

1. Enter **amount** and **transfer date** (cannot be in the future).
2. Add **reference** (bank reference if you have one).
3. Attach **receipt** (PDF or image, max 5 MB) if available.
4. Add comments for the admin.
5. Submit.

**After submit:** Status is **pending** until an administrator accepts the deposit. Cash is **not** credited until acceptance.

Bank transfer details for the fund are shown on the deposit form.

---

## 5. Fund account

**Menu:** My Accounts → **Fund account** → `/member/fund-account`

### What you see

- Fund balance
- Monthly contribution amount
- Total contributed (historical)
- Loan-related fund movements
- **Loan cap** (maximum you can borrow based on fund × multiplier)
- **Open-period contribution status** (posted / pending / exempt)
- Link to monthly statements

The fund account is **read-only** for movements — contributions and loan activity post through the collection and loan workflows.

---

## 6. Contributions

**Menu:** History → **My Contributions** → `/member/my-contributions`

### Viewing history

The table lists each period with amount, status, and posted date. Use filters to find a specific month or year.

### Applying this period’s contribution

When the **collection cycle is open** and you have enough cash, you may see **Apply this period**.

| Outcome | Meaning |
|---------|---------|
| Applied | Full contribution collected from your cash |
| Insufficient cash | Not enough cash to post the contribution |
| Already contributed | This period is already posted |
| Exempt | You are exempt for this cycle |

This action debits your **cash** and credits your **fund** (same as admin collection).

### Changing your monthly amount

Go to **Settings** → **Contributions** tab to change your monthly contribution tier. Changes may be blocked if your household has **unpaid arrears**. Administrators are notified when you change your tier.

---

## 7. Loans

### 7.1 My loans

**Menu:** Loans → **My loans** → `/member/my-loans`

Tabs typically include:

- **Active** — loans currently repaying
- **History** — completed or settled loans
- **Settle** — early settlement options
- **Apply** — shortcut to new application

Open a loan to see installments, schedule PDF, and repayment actions.

### 7.2 Request a loan

**Menu:** Loans → **Request a loan** → `/member/apply-for-loan`

**Wizard steps:** Amount → Purpose → Witnesses → Review → Submit

**Before you apply, check:**

- You are **eligible** (fund balance, membership tenure, no blocking status).
- You do not already have a **pending application**.
- You know the **maximum amount** shown (from fund balance).
- If the amount exceeds your fund balance, you may need a **guarantor** (per fund rules).

After submit, the application is **pending admin review**. You cannot edit it from the portal.

If blocked, you may **request an eligibility review** from the loans hub.

### 7.3 Pay this period (EMI)

On an **active** loan, use **Pay this period** to pay the open installment from your **cash** balance.

### 7.4 Early settlement

From the loan detail or settle tab, request **early settlement** if the fund allows it. Follow the on-screen amount and confirmation.

### 7.5 Loan calculator

**Menu:** Loans → **Loan calculator** → `/member/loan-calculator`

Estimate installments by amount and fund balance. This is **informational only** — actual terms are set on approval.

### 7.6 Guaranteed loans

**Menu:** Loans → **Guaranteed loans** (only if you are a guarantor on active loans)

View-only list of loans where you are guarantor.

---

## 8. Deposits

**Menu:** Self-Service → **My Deposits** → `/member/my-fund-postings`

Alternative path: submit from **Cash account** page.

| Field | Rule |
|-------|------|
| Transfer date | Today or earlier |
| Amount | As transferred |
| Attachment | Optional receipt (image/PDF) |
| Status | Pending → Accepted or Rejected by admin |

All deposits require **administrator acceptance** before cash is credited.

---

## 9. Cash out

**Menu:** Self-Service → **Cash out** → `/member/my-cash-out-requests`

### Requesting a payout

1. Open **New request** or use the shortcut on the cash account.
2. Review **available balance** (after EMI reserve).
3. Enter **amount** and optional notes.
4. Submit.

**Rules:**

- Payout comes from **cash only**.
- You need a **registered IBAN** in Settings → Payout details (contact support to update IBAN).
- Status: **Pending** → **Accepted** or **Rejected** with admin remarks.
- After acceptance, the fund transfers to your bank; this may take a few business days.

---

## 10. Statements and activity

### Monthly statements

**Menu:** Self-Service → **Statements** → `/member/my-statements`

- View generated statements by period.
- **Download PDF** for each month.
- Download center may also offer latest statement, loan schedule PDF, and activity export.

**PDF routes:** `/member/statements/{id}/pdf`, `/member/loans/{id}/schedule/pdf`

### Transaction activity

**Menu:** History → **Transactions** → `/member/activity`

Filterable feed of cash, fund, and loan ledger lines. Use **Export** for a spreadsheet download (`/member/activity/export`).

---

## 11. Settings and profile

**Menu:** Self-Service → **Settings** → `/member/settings`

### Profile tab

View member number, status, household profiles. **Edit profile** (`/member/edit-profile`) for name, phone, email, language, avatar, password, and parent PIN.

**Email change:** Must be unique. Changing email may affect dependent login setup.

### Contributions tab

Change monthly contribution tier (see [§6](#6-contributions)).

### Notifications tab

Choose how you receive alerts (email, database, push where enabled). Some channels may be required by the fund.

### Payout tab

View registered **IBAN** for cash-out. Contact support to change bank details.

---

## 12. Help, messages, and requests

**Menu:** Self-Service → **Help & FAQ** → `/member/help`

| Tab | Purpose |
|-----|---------|
| **Messages** | Inbox with administrators; compose with attachments |
| **Requests** | Submit a support request (category, subject, message) |
| **Alert history** | Past fund alerts sent to you |
| **FAQ** | Common questions |

Unread messages show a badge on **Help & FAQ**.

---

## 13. Household and dependents

**Menu:** Self-Service → **My dependents** (household heads only)

If you are the **parent** member in a household:

- View dependents and their contribution allocations
- **Apply for a dependent** (links to membership wizard)
- Update dependent contribution amounts
- **Switch profile** to act as a dependent (requires their PIN/password)
- Use **Return to parent portal** in the user menu when impersonating

---

## 14. What you cannot do in the portal

Members cannot:

- Accept their own deposits or cash-outs
- Post manual ledger adjustments
- Approve loans or change loan terms
- Clear bank statement lines
- See other members’ data

These are **administrator** functions.

---

## 15. Common questions

### Why is my cash balance zero after I transferred money?

Your deposit is probably still **pending**. Check **My Deposits** until status is **Accepted**.

### Why was my contribution not collected?

Common reasons: insufficient cash, cycle not open yet, exempt status, or you need to click **Apply this period**.

### Why can’t I apply for a loan?

Check eligibility message on the apply page: pending application, low fund balance, delinquent status, or missing guarantor.

### Why is less cash available than my balance shows?

Part of your cash may be **reserved for EMI**. See the cash account subheading.

### Who do I contact?

Use **Help & FAQ** → **Messages** or **Requests**, or contact your fund office directly.

---

## Quick reference — menu map

```
Overview          → Dashboard
My Accounts       → Cash account, Fund account
Loans             → My loans, Request a loan, Guaranteed loans*, Loan calculator
History           → My Contributions, Transactions
Self-Service      → Cash out, Statements, My Deposits, My dependents*, Settings, Help & FAQ
```

\*Visible only when applicable.
