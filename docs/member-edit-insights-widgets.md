# Member edit page — detail insights widgets

Compact, modern insights on the **Edit member** page (`/{record}/edit`), styled like the membership applications insights (`ff-app-insights`: gradients, KPI strip, 2–3 column grids).

Members are managed only on the edit page (no separate view page). Table links and widgets route to `MemberResource::getUrl('edit', …)`.

## Layout overview

The widget renders **above** the membership form and relation-manager tabs (same pattern as account and loan detail pages).

1. **Lifecycle stepper** — Joined → Active → Cycle → Loan / Arrears when relevant  
2. **Hero alert** — Contextual message (good standing, ready to contribute, arrears, active loan, etc.) with optional CTA  
3. **Cash & fund balance cards** — Gradient tiles linking to each account view  
4. **Six KPI tiles** — Cash, fund, monthly amount, cycle status, loan due, posted count (+ cash-activity sparkline)  
5. **Open cycle panel** — Period, required cash, fund-vs-monthly progress bar, link to contribution cycle  
6. **Loan or eligibility panel** — Active loan with repayment progress, or loan eligibility summary  
7. **Arrears banner** — When overdue installments or unpaid contribution periods exist  
8. **Quick-link cards** — Accounts, contributions, loans, household  
9. **Six-month contribution chart** + **recent ledger activity**  
10. **Household strip** — Parent link and dependents when applicable  

## Header actions (edit page)

- **Contribute** — Primary action when applicable  
- **Household** dropdown — Allocate to dependents  
- **Delinquency** dropdown — Sync status, mark delinquent, restore active, open delinquency workspace  

Page heading: member name. Subheading: `MEM-#### · Status`.

## Key files

| Purpose | Path |
|--------|------|
| Snapshot data | `app/Services/MemberDetailInsightsService.php` |
| Livewire widget | `app/Filament/Tenant/Widgets/MemberDetailInsightsWidget.php` |
| Blade UI | `resources/views/filament/tenant/widgets/member-detail-insights.blade.php` |
| Lifecycle stepper | `resources/views/components/member-lifecycle-stepper.blade.php` |
| Edit page wiring | `app/Filament/Tenant/Resources/Members/Pages/EditMember.php` |
| Member links in tables | `app/Filament/Support/MemberTableColumns.php` |
| Delinquency header actions | `app/Filament/Support/MemberDelinquencyActions.php` |
| Contribution header actions | `app/Filament/Tenant/Resources/Members/Concerns/InteractsWithMemberContributionHeaderActions.php` |
| List-page insights (unchanged) | `app/Services/MemberInsightsService.php`, `MemberInsightsWidget` |

## Refresh behavior

- Widget polls every **30s** on the edit page  
- Refreshes after **Save** on edit (`MemberResource::dispatchMemberDetailInsightsRefresh`)  
- Refreshes after a successful **Contribute** header action  
- List-page `MemberInsightsWidget` still refreshes via `dispatchInsightsRefresh` on create  

## Tests

- `tests/Unit/MemberDetailInsightsServiceTest.php` — Snapshot shape, balances, KPIs, trend, sparkline  
- `tests/Feature/Tenant/EditMemberPageTest.php` — Edit page loads with form and tabs  
- `tests/Feature/Tenant/MemberTableColumnsTest.php` — Edit URLs for column links  

## Related docs

- Delinquency actions: `docs/loan-delinquency-workflow.md` (references `EditMember.php`)  
- Applications insights pattern: `resources/views/filament/tenant/widgets/membership-application-insights.blade.php`  
- Account detail pattern: `resources/views/filament/tenant/widgets/account-detail-insights.blade.php`  
