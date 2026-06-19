# FundFlow SaaS — Member Portal

## Overview

The member portal is a dedicated Filament panel at **`/member`** on tenant domains. Members sign in with the same `tenant` guard as admins but see only their own data.

## Sidebar (prototype-aligned)

```
Overview
My Accounts
  Cash account · Fund account
Loans
  My loans · Request a loan · Guaranteed loans* · Loan calculator*
History
  Contributions · Transactions
Self-Service
  Cash out · Statements · My Deposits · My dependents* · Settings · Help & FAQ
```

\*Conditional: guaranteed loans (when guarantor), dependents (household heads), calculator always visible.

Unread admin messages show a badge on **Help & FAQ**. Active loans show a badge on **My loans**.

## Related code

| Area | Path |
|------|------|
| Navigation constants | `app/Filament/Member/Support/MemberNavigation.php` |
| Panel provider | `app/Providers/Filament/MemberPanelProvider.php` |
| Dashboard | `app/Filament/Member/Pages/MemberDashboard.php` |

See also `docs/member-portal-redesign-plan.md` and `docs/member-portal-implementation-plan.md`.
