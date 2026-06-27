# Member status & membership lifecycle specification

**Version:** 1.0  
**Status:** Approved for implementation  
**Scope:** Tenant member `status`, contribution-cycle flag, member requests, admin workflows, portal gating.

---

## 1. Canonical statuses

| Status | Code | Meaning |
|--------|------|---------|
| **Active** | `active` | Normal operating membership. |
| **Inactive** | `inactive` | **Paused** membership (member-requested freeze or admin freeze). Still a member; balances preserved; not exited. |
| **Delinquent** | `delinquent` | Compliance hold — arrears breach (contributions and/or loan installments). |
| **Suspended** | `suspended` | Administrative or loan-consequence hold (disciplinary, post–guarantor-transfer restriction). |
| **Withdrawn** | `withdrawn` | Voluntary exit from the fund. Settlement may proceed; not a hard payout freeze. |
| **Terminated** | `terminated` | Involuntary exit — **hard payout freeze** until admin review. |

`inactive` is **distinct from `withdrawn`**: inactive = temporary pause; withdrawn = left the fund voluntarily.

### Dashboard aggregates

| Label | Includes |
|-------|----------|
| **Non-active** (insights) | `inactive`, `delinquent`, `suspended`, `withdrawn`, `terminated` |
| **Exit states** | `withdrawn`, `terminated` |
| **Compliance holds** | `delinquent`, `suspended` |

---

## 2. Separate flag: `contribution_cycles_active`

| Column | Type | Default | Purpose |
|--------|------|---------|---------|
| `contribution_cycles_active` | `boolean` | `true` | When `true`, member remains in **automatic contribution cycles** even while `suspended`. |

**Use case:** After a loan is transferred to a guarantor, the original borrower is `suspended` (portal blocked, no new loans) but **must still contribute**. Set `contribution_cycles_active = true` on transfer.

**Rules:**

- Admin **Suspend** → sets `contribution_cycles_active = false`.
- **Guarantor transfer** → `status = suspended`, `contribution_cycles_active = true`.
- **Restore suspended** → `status = active` or `delinquent` (see §5), `contribution_cycles_active = true`.
- **Inactive / withdrawn / terminated** → `contribution_cycles_active = false`.

---

## 3. Capability matrix (target behaviour)

| Capability | Active | Inactive | Delinquent | Suspended | Suspended + cycles flag | Withdrawn | Terminated |
|------------|:------:|:--------:|:----------:|:---------:|:-----------------------:|:---------:|:----------:|
| Member portal | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Apply for loan | ✅* | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Auto contribution cycles | ✅ | ❌ | ✅ | ❌ | ✅ | ❌ | ❌ |
| Admin: Contribute | ✅ | ❌ | ✅ | ❌ | ✅ | ❌ | ❌ |
| Admin: Repayment | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Admin: Adjust cash/fund | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Member: cash-out request | ✅* | ❌ | ❌ | ❌ | ❌ | ✅† | ❌‡ |
| Payout / settlement | ✅ | ❌ | ❌ | ❌ | ❌ | ✅ | ❌‡ |
| Be guarantor | ✅* | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Household parent (new deps) | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |

\* Subject to fund rules, balances, tenure, etc.  
† Withdrawal settlement during exit workflow.  
‡ Terminated = hard payout freeze until admin **releases payout review** or **reinstates**.

---

## 4. State machine



![Diagram 1](../_assets//var/www/fundflow-saas/docs/member-status-spec/diagram-01.png)



### Forbidden transitions (enforced in `MemberStatusService`)

- No transition **from** `terminated` except `reinstate`.
- No transition **from** `withdrawn` except `reinstate` or `terminate`.
- `suspend` blocked for `withdrawn`, `terminated`.
- `freeze` only from `active` or `delinquent`.
- `terminate` blocked when already `terminated`.

---

## 5. `MemberStatusService` API

| Method | From | To | Side effects |
|--------|------|-----|--------------|
| `freeze(Member, reason)` | `active`, `delinquent` | `inactive` | `contribution_cycles_active=false`; audit `MEMBER_FROZEN`; revoke portal sessions |
| `unfreeze(Member)` | `inactive` | `active` or `delinquent` | If arrears → `delinquent`; else `active`; audit `MEMBER_UNFROZEN` |
| `suspend(Member, reason)` | not `suspended`/`withdrawn`/`terminated` | `suspended` | `contribution_cycles_active=false`; audit `MEMBER_SUSPENDED` |
| `suspendForGuarantorTransfer(Member)` | any non-exit | `suspended` | `contribution_cycles_active=true`; audit `MEMBER_SUSPENDED_GUARANTOR_TRANSFER` |
| `restoreSuspended(Member)` | `suspended` | `active` or `delinquent` | `contribution_cycles_active=true`; audit `MEMBER_RESTORED` |
| `withdraw(Member, reason)` | not `withdrawn`/`terminated` | `withdrawn` | `contribution_cycles_active=false`; audit `MEMBER_WITHDRAWN` |
| `terminate(Member, reason)` | not `terminated` | `terminated` | `contribution_cycles_active=false`, `payout_frozen_at=now()`; audit `MEMBER_TERMINATED` |
| `reinstate(Member, reason)` | `withdrawn`, `terminated` | `active` | Zero cash/fund via mirrored journal; clear `payout_frozen_at`; audit `MEMBER_REINSTATED` |
| `releasePayoutReview(Member, reason)` | `terminated` | `terminated` | Clears `payout_frozen_at` only; allows settlement cash-out; audit `MEMBER_PAYOUT_RELEASED` |

Delinquency sync remains in `LoanDelinquencyService` (`markMemberDelinquent`, `restoreMemberActive`, `syncMemberDelinquencyStatus*`).

---

## 6. Member requests (portal)

| Type | Requester | On approve | On reject |
|------|-----------|------------|-----------|
| `freeze_membership` | Active member | `MemberStatusService::freeze()` | No change |
| `unfreeze_membership` | Inactive member | `MemberStatusService::unfreeze()` | No change |
| `withdraw_membership` | Active/inactive/delinquent/suspended | `MemberStatusService::withdraw()` | No change |

Validation:

- One pending request per type per member.
- Freeze: only `active` (not already delinquent/suspended — must resolve those first).
- Unfreeze: only `inactive`.
- Withdraw: not `withdrawn`/`terminated`; warn if active loan obligations.

---

## 7. Household rules

When a **parent** enters `inactive`, `withdrawn`, or `terminated`:

- **Dependents** are **portal-blocked** until admin resolves (reassign parent, withdraw dependents, or reinstate parent).
- Enforced in `HouseholdMemberService::memberCanAccessPortal()` and login picker.
- Admin UI: banner on parent record listing blocked dependents.

When parent is `suspended` or `delinquent`: existing rules apply (dependents with `active` status may still access if separated/direct login).

---

## 8. Admin UI grouping (`MemberFilamentActions`)

| Group | Actions | Visibility summary |
|-------|---------|-------------------|
| **Treasury** | Contribute, Repayment, Adjust cash, Adjust fund | Contribute: `MemberMembershipPolicy::canAdminContribute()` |
| **Communicate** | Message, Notification | Has `user_id` |
| **Membership** | Application, **Freeze**, **Unfreeze**, Suspend, Restore suspended, **Withdraw**, Terminate, **Reinstate**, **Release payout** | Per state machine |
| **Compliance** | Sync delinquency, Mark delinquent, Restore active, Annual sub, Admin override, Delete | Delinquency actions: `active`/`delinquent` |

### Critical improvement

- **Remove** editable `status` `Select` from `MemberForm`.
- Show **read-only** status badge + `status_reason` / `status_changed_at` if present.
- All status changes go through workflow actions + audit log.

### List tabs (`MemberListTabService`)

Add: `inactive`, `withdrawn`, `terminated` tabs (keep `all`, `active`, `migration_pending`, `delinquent`, `suspended`).

---

## 9. `MemberMembershipPolicy` (central gating)

Single class used by portal, Filament, contribution cycles, cash-out, loan eligibility:

- `canAccessPortal(Member): bool`
- `canAccessPortalConsideringHousehold(Member): bool`
- `canApplyForLoan(Member): bool` — `status === active` only
- `canParticipateInContributionCycles(Member): bool`
- `canAdminContribute(Member): bool`
- `canRequestCashOut(Member): bool`
- `canReceivePayout(Member): bool` — false when `payout_frozen_at` set
- `canBeGuarantor(Member): bool` — `active` + no arrears
- `canAssignDependents(Member): bool` — parent `active` only

---

## 10. Reinstate — balance clearing

On `reinstate()`:

1. Post balancing journal entries to bring member **cash** and **fund** to **0.00** (with master pool mirrors per accounting rules).
2. Description: `Membership reinstatement — balance reset`.
3. Do **not** delete historical transactions.
4. Set `status = active`, clear `payout_frozen_at`, `contribution_cycles_active = true`.

---

## 11. Legacy import

`LegacyMemberStatusMapper`:

| Legacy label | Maps to |
|--------------|---------|
| `inactive` | `inactive` (no longer `withdrawn`) |
| `منسحب` / `resigned` | `withdrawn` |
| `معلق` | `suspended` |
| `متأخر` | `delinquent` |
| `منتهي` | `terminated` |
| `مستمر` / `continuing` | `active` |

---

## 12. Migration

```sql
-- members table
ALTER status ENUM add 'inactive' (order: active, inactive, delinquent, suspended, withdrawn, terminated)
ADD contribution_cycles_active BOOLEAN NOT NULL DEFAULT TRUE
ADD payout_frozen_at TIMESTAMP NULL
ADD status_reason VARCHAR(500) NULL
ADD status_changed_at TIMESTAMP NULL
```

---

## 13. Open item (default assumed)

**Withdraw path:** Member submits `withdraw_membership` request → admin approves → `withdrawn`. No self-service instant withdraw.

---

## 14. Test plan

- `MemberMembershipPolicyTest` — matrix per status + flag combinations
- `MemberStatusServiceTest` — transitions, forbidden paths, reinstate zeroing
- `MemberRequestServiceTest` — freeze / unfreeze / withdraw approve flows
- `LoanGuarantorTransferServiceTest` — suspended + `contribution_cycles_active`
- `ContributionCycleServiceTest` — delinquent + flagged suspended in cycles
- `HouseholdMemberServiceTest` — parent exit blocks dependents
- `MemberCashOutServiceTest` — terminated payout freeze
- Update `MemberImportExportTest`, `MemberPortalTest`, architecture tests as needed
