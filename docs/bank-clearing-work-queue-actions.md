# Bank clearing Work queue — row and bulk actions

| Field | Value |
|-------|-------|
| **Audience** | Tenant admins / implementers |
| **UI** | Admin → **Bank clearing** (`/bank-accounts`) → **Work queue** tab |
| **Table** | [`BankClearingQueueTable`](../app/Filament/Tenant/Resources/BankAccounts/Tables/BankClearingQueueTable.php) |
| **Actions** | [`BankClearingQueueActions`](../app/Filament/Support/BankClearingQueueActions.php) |
| **Related** | [bank-clearing-workspace-implementation-plan.md](bank-clearing-workspace-implementation-plan.md), [fund-flow-dynamics-admins.md](fund-flow-dynamics-admins.md) |

This document explains **Source** modes, **row** actions, and **bulk** actions on the Work queue.

---

## 1. Source modes (primary control)

The chip bar above the table is labeled **Source** (not a secondary filter). It sets `queueFilter` in the URL:

| Mode | Key | Rows shown |
|------|-----|------------|
| All open | `all` | Bank file + operations |
| From bank file | `bank_file` | Imported CSV lines awaiting posting |
| From operations | `operations` | Uncleared operational clearance rows |

Count badges come from `BankClearingQueueService::counts()`.

**Default when the URL has no `queueFilter`:**

- Only bank file has items → `bank_file`
- Only operations has items → `operations`
- Both have items → `bank_file`
- Both empty → `all`
- Explicit `?queueFilter=` or legacy tab deep links are respected

The table **Source** column still shows the badge per row. The duplicate Source *table filter* was removed; chips own slice selection.

---

## 2. Two paths

| Source | What the row is | Typical next step |
|--------|-----------------|-------------------|
| **From bank file** | Real statement line (`imported` / `mirrored`) | **Post cash** → **Post member** |
| **From operations** | Pending clearance after accept | **Match** (or **Auto-match** / **Clear**) |

Do **not** use **Post member** on operations rows — cash was already posted when the operation was accepted.

---

## 3. Row actions (single Actions group)

Row click opens **View**. All mutative actions live in one **Actions** dropdown. Which children are **registered** depends on Source mode; which children are **visible** depends on the row.

### When mode is From bank file (or All + bank-file row)

Post cash · Post member · Auto-match · View · Ignore · Delete

### When mode is From operations (or All + operations row)

Auto-match · Match · Clear · View · Delete

When mode is a single slice, only that slice’s actions are registered. When **All open**, both slices’ actions are registered (Auto-match once); each action stays visibility-gated per row.

### Action reference (short labels)

| Name | Label | Applies to | Does |
|------|-------|------------|------|
| `mirrorToCash` | Post cash | Bank file (`imported`) | `FundFlowService::mirrorToCash` |
| `postToMember` | Post member | Bank file (`imported`/`mirrored`) | Mirror if needed + credit/debit member cash |
| `autoMatch` | Auto-match | Either, unique counterpart only | `autoMatchWhenUnique` → `clearMatchPair` |
| `matchToBankLine` | Match | Operations | Pick CSV line → `clearMatchPair` |
| `clearWithoutEvidence` | Clear | Operations | Clear without CSV pairing |
| `ignore` | Ignore | Bank file `imported` | Status → `ignored` |
| `delete` | Delete | Bank file | `BankTransactionDeletion` |
| `deletePendingOperational` | Delete | Operations | Reverse linked op as needed, remove pending line |
| View | View | Always | Detail modal + suggested next step |

Modals keep full descriptive copy.

---

## 4. Bulk actions

Toolbar contents depend on Source mode:

| Bulk | Label | Shown when |
|------|-------|------------|
| `matchAllUnique` | Auto-match | Always |
| `matchSelected` | Match | Always |
| `clearWithoutEvidenceBulk` | Clear | All or operations |
| `mirrorSelectedToCash` | Post cash | All or bank file |
| `postSelectedToMember` | Post member | All or bank file |
| `ignoreSelected` | Ignore | All or bank file |
| `deleteQueueRows` | Delete | Always |

Ineligible rows in a mixed selection are skipped. Refresh reloads the table.

---

## 5. Suggested “Next step”

Optional column / View modal uses short labels: Auto-match, Match, Post cash, Post member, Clear.

---

## 6. Empty states

| Mode | Copy summary |
|------|----------------|
| Bank file | No lines awaiting posting — import, then Post cash / Post member |
| Operations | No rows awaiting evidence — Match or Clear |
| All | Combined posting / matching guidance |

---

## 7. Code map

| Concern | Class |
|---------|--------|
| Table | `BankClearingQueueTable` |
| Actions | `BankClearingQueueActions::groupedRecordActions($filter)` / `toolbarBulkActions($filter)` |
| Source defaults | `BankClearingTabRegistry::defaultQueueFilter()` |
| Slice / suggestion | `BankClearingQueueService`, `BankClearingQueuePresenter` |
| Match / clear | `BankClearingMatchService` |
| Post | `FundFlowService` |
