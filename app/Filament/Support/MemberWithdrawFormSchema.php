<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Tenant\Member;
use App\Services\MemberWithdrawalSettlementService;
use Filament\Actions\Action;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * Shared leave-fund form building blocks (member wizard + admin sections).
 */
final class MemberWithdrawFormSchema
{
    /**
     * Member portal: multi-step wizard inside the leave-fund request modal.
     *
     * @param  list<string>  $blockers
     * @return list<Wizard>
     */
    public static function requestWizard(Member $member, array $blockers): array
    {
        $dependentOptions = self::dependentOptions($member);
        $hasDependents = $dependentOptions !== [];

        $steps = [];

        if ($hasDependents) {
            $steps[] = Step::make(__('Household'))
                ->description(__('Dependents'))
                ->icon(Heroicon::OutlinedUserGroup)
                ->schema(self::householdFields($dependentOptions));
        }

        $steps[] = Step::make(__('Checklist'))
            ->description(__('Before submitting'))
            ->icon(Heroicon::OutlinedClipboardDocumentCheck)
            ->schema(self::checklistFields($member, $blockers));

        return [
            Wizard::make($steps)
                ->skippable(false)
                ->persistStepInQueryString(null),
        ];
    }

    /**
     * Admin portal: sectioned single form (no wizard).
     *
     * @param  list<string>  $blockers
     * @return list<\Filament\Schemas\Components\Component|Component>
     */
    public static function requestSections(
        Member $member,
        array $blockers,
        bool $includeWithdrawDate = false,
        bool $includeHoldPayout = false,
    ): array {
        $dependentOptions = self::dependentOptions($member);
        $sections = [];

        if ($dependentOptions !== []) {
            $sections[] = Section::make(__('Household'))
                ->description(__('How dependents should be handled when this member leaves.'))
                ->icon('heroicon-o-user-group')
                ->schema(self::householdFields($dependentOptions));
        }

        $checklistSchema = self::checklistFields($member, $blockers);

        if ($includeWithdrawDate) {
            $checklistSchema[] = MemberFilamentActions::withdrawDateField();
        }

        if ($includeHoldPayout) {
            $checklistSchema[] = Toggle::make('hold_payout')
                ->label(__('Hold payout for admin review'))
                ->helperText(__('Settles loans but keeps balances in the member account until payout is released. Skips auto cash-out accept.'))
                ->default(false);
        }

        $sections[] = Section::make(__('Before you continue'))
            ->description(__('Review readiness and what happens after approval.'))
            ->icon('heroicon-o-clipboard-document-check')
            ->schema($checklistSchema);

        return $sections;
    }

    /**
     * @param  array<int|string, string>  $dependentOptions
     * @return list<Component>
     */
    private static function householdFields(array $dependentOptions): array
    {
        return [
            Radio::make('household_mode')
                ->label(__('Household when leaving'))
                ->options([
                    MemberWithdrawalSettlementService::HOUSEHOLD_INCLUDE_DEPENDENTS => __('Withdraw me and all dependents'),
                    MemberWithdrawalSettlementService::HOUSEHOLD_PERMANENT_PARENT => __('Elect a permanent household parent'),
                ])
                ->descriptions([
                    MemberWithdrawalSettlementService::HOUSEHOLD_INCLUDE_DEPENDENTS => __('Each dependent is fully settled (loans, guarantor clearance, cash-out) and withdrawn.'),
                    MemberWithdrawalSettlementService::HOUSEHOLD_PERMANENT_PARENT => __('One eligible dependent becomes the true household head; siblings are reassigned to them. Only you leave.'),
                ])
                ->required()
                ->live(),
            Select::make('permanent_parent_member_id')
                ->label(__('Permanent household parent'))
                ->options($dependentOptions)
                ->searchable()
                ->native(false)
                ->visible(fn ($get): bool => $get('household_mode') === MemberWithdrawalSettlementService::HOUSEHOLD_PERMANENT_PARENT)
                ->required(fn ($get): bool => $get('household_mode') === MemberWithdrawalSettlementService::HOUSEHOLD_PERMANENT_PARENT)
                ->helperText(__('Must be able to log in, be independent, and have cash capacity. They become the permanent parent for remaining dependents.')),
            Textarea::make('reason')
                ->label(__('Reason (optional)'))
                ->rows(3)
                ->maxLength(500)
                ->columnSpanFull(),
        ];
    }

    /**
     * @param  list<string>  $blockers
     * @return list<\Filament\Schemas\Components\Component|Component>
     */
    private static function checklistFields(Member $member, array $blockers): array
    {
        $assessment = app(MemberWithdrawalSettlementService::class)->assess($member);
        $hasHouseholdStep = self::dependentOptions($member) !== [];

        $fields = [
            Placeholder::make('withdrawal_summary')
                ->hiddenLabel()
                ->content(new HtmlString(self::summaryHtml($assessment))),
            Placeholder::make('blockers')
                ->hiddenLabel()
                ->content(new HtmlString(self::blockersHtml($blockers))),
            Actions::make([
                self::notifyBorrowersAction($member),
            ]),
            Placeholder::make('impact')
                ->hiddenLabel()
                ->content(new HtmlString(self::impactHtml())),
        ];

        if (! $hasHouseholdStep) {
            array_unshift(
                $fields,
                Textarea::make('reason')
                    ->label(__('Reason (optional)'))
                    ->rows(3)
                    ->maxLength(500)
                    ->columnSpanFull(),
            );
        }

        return $fields;
    }

    private static function notifyBorrowersAction(Member $member): Action
    {
        return Action::make('notifyBorrowersToReplaceGuarantor')
            ->label(__('Notify borrowers to replace guarantor'))
            ->icon('heroicon-o-bell-alert')
            ->color('warning')
            ->visible(fn (): bool => app(MemberWithdrawalSettlementService::class)->unresolvedGuarantorLoans($member) !== [])
            ->requiresConfirmation()
            ->modalHeading(__('Notify borrowers?'))
            ->modalDescription(__('Sends a message to each borrower who still lists you as guarantor, asking them to propose a replacement. This does not submit your leave request.'))
            ->action(function () use ($member): void {
                try {
                    $result = app(MemberWithdrawalSettlementService::class)->notifyBorrowersToReplaceGuarantor($member);

                    Notification::make()
                        ->title(__('Borrowers notified'))
                        ->body(__('Notified :count borrower(s) to replace you as guarantor.', [
                            'count' => $result['notified'],
                        ]))
                        ->success()
                        ->send();
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title(__('Could not notify'))
                        ->body(collect($exception->errors())->flatten()->first())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * @return array<int|string, string>
     */
    private static function dependentOptions(Member $member): array
    {
        if (! $member->isParent()) {
            return [];
        }

        return $member->dependents()
            ->where('status', 'active')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @param  array{
     *     active_loan_count: int,
     *     settlement_required_cash: float,
     *     member_cash_balance: float,
     *     member_fund_balance: float,
     *     projected_cash_out: float,
     *     pipeline_loan_count: int,
     *     guarantor_obligation_count: int,
     *     active_dependent_count: int,
     * }  $assessment
     */
    public static function summaryHtml(array $assessment): string
    {
        $lines = [
            __('Active loans to settle: :count', ['count' => $assessment['active_loan_count']]),
            __('Settlement cash required: :amount', ['amount' => number_format($assessment['settlement_required_cash'], 2)]),
            __('Member cash balance: :amount', ['amount' => number_format($assessment['member_cash_balance'], 2)]),
            __('Member fund balance: :amount', ['amount' => number_format($assessment['member_fund_balance'], 2)]),
            __('Projected cash-out: :amount', ['amount' => number_format($assessment['projected_cash_out'], 2)]),
        ];

        if ($assessment['pipeline_loan_count'] > 0) {
            $lines[] = __('Open loan applications: :count', ['count' => $assessment['pipeline_loan_count']]);
        }

        if ($assessment['guarantor_obligation_count'] > 0) {
            $lines[] = __('Active guarantor obligations: :count', ['count' => $assessment['guarantor_obligation_count']]);
        }

        if (($assessment['active_dependent_count'] ?? 0) > 0) {
            $lines[] = __('Active dependents: :count', ['count' => $assessment['active_dependent_count']]);
        }

        return view('filament.partials.freeze-callout', [
            'tone' => 'info',
            'title' => __('Withdrawal summary'),
            'items' => array_map(static fn (string $line): string => e($line), $lines),
        ])->render();
    }

    /**
     * @param  list<string>  $blockers
     */
    public static function blockersHtml(array $blockers): string
    {
        if ($blockers === []) {
            return view('filament.partials.freeze-callout', [
                'tone' => 'success',
                'title' => __('Ready to submit'),
                'body' => e(__('No blocking pending items or unresolved guarantor roles.')),
            ])->render();
        }

        return view('filament.partials.freeze-callout', [
            'tone' => 'danger',
            'title' => __('Resolve these before leave can proceed'),
            'items' => array_map(static fn (string $reason): string => e($reason), $blockers),
        ])->render();
    }

    public static function impactHtml(): string
    {
        return view('filament.partials.freeze-callout', [
            'tone' => 'info',
            'title' => __('What happens after approval'),
            'items' => [
                e(__('Active loans are early-settled from cash and fund.')),
                e(__('Remaining fund moves to cash; residual cash is auto cash-out accepted (uncleared bank line until statement match).')),
                e(__('Membership ends; portal access is blocked.')),
                e(__('Reinstate later starts with cleared ledger balances; prior cash-out is not reversed.')),
            ],
        ])->render();
    }
}
