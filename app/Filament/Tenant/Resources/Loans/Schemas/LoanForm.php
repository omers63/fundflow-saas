<?php

namespace App\Filament\Tenant\Resources\Loans\Schemas;

use App\Filament\Support\LoanApplicationFundingFields;
use App\Filament\Support\LoanApprovalPreview;
use App\Filament\Support\MoneyDisplay;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\Loans\LoanEligibilityService;
use App\Services\Loans\LoanQueuePriorityScoreService;
use App\Support\LoanEligibilityGate;
use App\Support\LoanFundingStrategy;
use App\Support\LoanSettings;
use Closure;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

class LoanForm
{
    public static function configure(Schema $schema, bool $forCreate = false): Schema
    {
        if ($forCreate) {
            return self::configureCreateWizard($schema);
        }

        return self::configureEditForm($schema);
    }

    public static function configureCreateWizard(Schema $schema): Schema
    {
        $currency = Setting::get('general', 'currency', 'USD');
        $memberResolver = self::memberResolver();

        [$strategyRadio, $strategyFixed, $excessDisposition, $fundingPreview] = LoanApplicationFundingFields::components(
            $memberResolver,
            amountField: 'amount_requested',
        );

        return $schema
            ->components([
                Wizard::make([
                    Step::make(__('Borrower'))
                        ->icon(Heroicon::OutlinedUserCircle)
                        ->schema([
                            Select::make('member_id')
                                ->label(__('Member'))
                                ->options(Member::active()->orderBy('name')->pluck('name', 'id'))
                                ->searchable()
                                ->required()
                                ->live(),
                            Placeholder::make('member_snapshot')
                                ->label(__('Member fund snapshot'))
                                ->content(function (Get $get) use ($currency): HtmlString {
                                    $member = self::resolveMember($get);

                                    if ($member === null) {
                                        return new HtmlString(
                                            '<p class="text-sm text-gray-500 dark:text-gray-400">'.e(__('Select a member to see fund balance and loan limits.')).'</p>'
                                        );
                                    }

                                    $fundBal = $member->getFundBalance();
                                    $max = LoanSettings::maxLoanAmountForMember($fundBal);
                                    $failed = app(LoanEligibilityService::class)->getFailedGates($member);

                                    $status = $failed === []
                                        ? '<span class="ff-chip bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300">'.e(__('Eligible')).'</span>'
                                        : '<span class="ff-chip bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300">'.e(__('Eligibility review needed')).'</span>';

                                    return new HtmlString(
                                        '<div class="space-y-2 rounded-lg border border-gray-200 bg-gray-50/80 px-3 py-2 dark:border-white/10 dark:bg-white/5">'
                                        .'<div class="flex flex-wrap items-center gap-2">'.$status.'</div>'
                                        .'<div class="grid gap-2 sm:grid-cols-2">'
                                        .self::snapshotRow(__('Fund balance'), MoneyDisplay::format($fundBal, $currency) ?? '—')
                                        .self::snapshotRow(__('Maximum loan'), MoneyDisplay::format($max, $currency) ?? '—')
                                        .'</div>'
                                        .($failed !== [] ? '<p class="text-xs text-gray-500 dark:text-gray-400">'.e(__('Blocked gates: :gates', [
                                            'gates' => implode(', ', array_map(
                                                fn (string $gate): string => (string) (LoanEligibilityGate::labels()[$gate] ?? $gate),
                                                array_keys($failed),
                                            )),
                                        ])).'</p>' : '')
                                        .'</div>'
                                    );
                                })
                                ->columnSpanFull(),
                        ]),
                    Step::make(__('Amount & funding'))
                        ->icon(Heroicon::OutlinedBanknotes)
                        ->schema([
                            TextInput::make('amount_requested')
                                ->label(__('Amount requested'))
                                ->numeric()
                                ->required()
                                ->minValue(1)
                                ->live(onBlur: true)
                                ->prefix($currency),
                            $strategyRadio,
                            $strategyFixed,
                            $excessDisposition,
                            $fundingPreview,
                        ])
                        ->columns(2),
                    Step::make(__('Terms'))
                        ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                        ->schema([
                            Select::make('guarantor_member_id')
                                ->label(__('Guarantor'))
                                ->helperText(__('Required when the amount exceeds the member fund balance for the chosen strategy.'))
                                ->options(fn (Get $get): array => Member::query()
                                    ->active()
                                    ->when(filled($get('member_id')), fn ($query) => $query->whereKeyNot($get('member_id')))
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all())
                                ->searchable()
                                ->required(fn (Get $get): bool => self::guarantorRequired($get))
                                ->nullable(fn (Get $get): bool => ! self::guarantorRequired($get)),
                            Select::make('grace_cycles')
                                ->label(__('Grace cycles before first repayment'))
                                ->options([
                                    0 => __('None'),
                                    1 => __('One cycle'),
                                    2 => __('Two cycles'),
                                ])
                                ->default(1)
                                ->required()
                                ->native(false),
                            Toggle::make('is_emergency')
                                ->label(__('Emergency loan'))
                                ->helperText(__('Bypasses standard queue and uses the emergency fund tier.')),
                            Textarea::make('purpose')
                                ->label(__('Purpose'))
                                ->rows(2)
                                ->maxLength(2000)
                                ->columnSpanFull()
                                ->placeholder(__('Describe how the member plans to use the funds…')),
                        ])
                        ->columns(2),
                    Step::make(__('Compliance'))
                        ->icon(Heroicon::OutlinedShieldCheck)
                        ->schema([
                            Toggle::make('override_eligibility')
                                ->label(__('Override eligibility'))
                                ->live()
                                ->helperText(function (Get $get): ?string {
                                    $member = self::resolveMember($get);

                                    if ($member === null) {
                                        return __('Enable only when the member fails standard eligibility gates.');
                                    }

                                    $failed = app(LoanEligibilityService::class)->getFailedGates($member);

                                    if ($failed === []) {
                                        return __('This member passes all eligibility gates.');
                                    }

                                    return __('Blocked gates: :gates', [
                                        'gates' => implode(', ', array_map(
                                            fn (string $gate): string => (string) (LoanEligibilityGate::labels()[$gate] ?? $gate),
                                            array_keys($failed),
                                        )),
                                    ]);
                                }),
                            Textarea::make('eligibility_override_reason')
                                ->label(__('Override reason'))
                                ->rows(2)
                                ->required(fn (Get $get): bool => (bool) $get('override_eligibility'))
                                ->visible(fn (Get $get): bool => (bool) $get('override_eligibility'))
                                ->placeholder(__('Document board approval or the business reason for the exception…'))
                                ->columnSpanFull(),
                        ]),
                ])
                    ->submitAction(new HtmlString(Blade::render(<<<'BLADE'
                        <x-filament::button type="button" wire:click="create" size="sm">
                            {{ __('Create') }}
                        </x-filament::button>
                    BLADE)))
                    ->contained(false)
                    ->columnSpanFull(),
            ]);
    }

    public static function configureEditForm(Schema $schema): Schema
    {
        $currency = Setting::get('general', 'currency', 'USD');

        return $schema
            ->components([
                Section::make(__('Loan application'))
                    ->columns(2)
                    ->schema([
                        Select::make('member_id')
                            ->label(__('Member'))
                            ->options(Member::active()->pluck('name', 'id'))
                            ->searchable()
                            ->required()
                            ->disabled(),
                        TextInput::make('amount_requested')
                            ->label(__('Amount requested'))
                            ->numeric()
                            ->prefix($currency)
                            ->required()
                            ->minValue(1),
                        Select::make('guarantor_member_id')
                            ->label(__('Guarantor'))
                            ->options(Member::active()->pluck('name', 'id'))
                            ->searchable()
                            ->nullable(),
                        Toggle::make('is_emergency')
                            ->label(__('Emergency loan')),
                        Toggle::make('has_grace_cycle')
                            ->label(__('Grace cycle'))
                            ->default(true),
                        Textarea::make('purpose')
                            ->label(__('Purpose'))
                            ->rows(3)
                            ->maxLength(2000)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * Pending application review — structured for loan officers (no post-disbursement data).
     */
    public static function configureReviewForm(Schema $schema, Closure $loanResolver): Schema
    {
        $currency = Setting::get('general', 'currency', 'USD');
        $memberResolver = fn (Get $get): ?Member => Member::query()
            ->with('fundAccount')
            ->find($get('member_id'));

        [$strategyRadio, $strategyFixed, $excessDisposition, $fundingPreview] = LoanApplicationFundingFields::components(
            $memberResolver,
            amountField: 'amount_requested',
        );

        return $schema
            ->components([
                Section::make(__('Application queue'))
                    ->description(__('Use the header actions to approve, reject, or cancel after reviewing the application below.'))
                    ->schema([
                        Placeholder::make('queue_context')
                            ->hiddenLabel()
                            ->content(function () use ($loanResolver, $currency): HtmlString {
                                $loan = $loanResolver();
                                $loan->loadMissing('member');
                                $priority = app(LoanQueuePriorityScoreService::class)->calculate($loan);
                                $waiting = $loan->applied_at?->diffForHumans() ?? '—';

                                return new HtmlString(
                                    '<div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">'
                                    .self::snapshotRow(__('Member'), (string) ($loan->member?->name ?? '—'))
                                    .self::snapshotRow(__('Applied'), $loan->applied_at?->toDayDateTimeString() ?? '—')
                                    .self::snapshotRow(__('Waiting'), $waiting)
                                    .self::snapshotRow(__('Priority score'), (string) $priority)
                                    .self::snapshotRow(__('Queue position'), $loan->queue_position !== null ? (string) $loan->queue_position : '—')
                                    .self::snapshotRow(__('Amount requested'), MoneyDisplay::format((float) $loan->amount_requested, $currency) ?? '—')
                                    .'</div>'
                                );
                            })
                            ->columnSpanFull(),
                        Placeholder::make('eligibility_summary')
                            ->label(__('Eligibility'))
                            ->content(function () use ($loanResolver, $currency): HtmlString {
                                $loan = $loanResolver();
                                $member = $loan->member;

                                if ($member === null) {
                                    return new HtmlString(
                                        '<p class="text-sm text-gray-500 dark:text-gray-400">'.e(__('Member not found.')).'</p>'
                                    );
                                }

                                $member->loadMissing('fundAccount');
                                $fundBal = $member->getFundBalance();
                                $max = LoanSettings::maxLoanAmountForMember($fundBal);
                                $failed = app(LoanEligibilityService::class)->getFailedGates($member);

                                $status = $failed === []
                                    ? '<span class="ff-chip bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300">'.e(__('Eligible')).'</span>'
                                    : '<span class="ff-chip bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300">'.e(__('Eligibility review needed')).'</span>';

                                return new HtmlString(
                                    '<div class="space-y-2 rounded-lg border border-gray-200 bg-gray-50/80 px-3 py-2 dark:border-white/10 dark:bg-white/5">'
                                    .'<div class="flex flex-wrap items-center gap-2">'.$status.'</div>'
                                    .'<div class="grid gap-2 sm:grid-cols-2">'
                                    .self::snapshotRow(__('Fund balance'), MoneyDisplay::format($fundBal, $currency) ?? '—')
                                    .self::snapshotRow(__('Maximum loan'), MoneyDisplay::format($max, $currency) ?? '—')
                                    .'</div>'
                                    .($failed !== [] ? '<p class="text-xs text-gray-500 dark:text-gray-400">'.e(__('Blocked gates: :gates', [
                                        'gates' => implode(', ', array_map(
                                            fn (string $gate): string => (string) (LoanEligibilityGate::labels()[$gate] ?? $gate),
                                            array_keys($failed),
                                        )),
                                    ])).'</p>' : '')
                                    .'</div>'
                                );
                            })
                            ->columnSpanFull(),
                    ]),
                Section::make(__('Application details'))
                    ->description(__('Adjust before approval if the member or board requested changes.'))
                    ->columns(2)
                    ->schema([
                        Select::make('member_id')
                            ->label(__('Member'))
                            ->options(Member::active()->orderBy('name')->pluck('name', 'id'))
                            ->disabled()
                            ->dehydrated(),
                        TextInput::make('amount_requested')
                            ->label(__('Amount requested'))
                            ->numeric()
                            ->prefix($currency)
                            ->required()
                            ->minValue(1)
                            ->live(onBlur: true),
                        Select::make('guarantor_member_id')
                            ->label(__('Guarantor'))
                            ->options(fn (Get $get): array => Member::query()
                                ->active()
                                ->when(filled($get('member_id')), fn ($query) => $query->whereKeyNot($get('member_id')))
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->required(fn (Get $get): bool => self::guarantorRequired($get))
                            ->nullable(fn (Get $get): bool => ! self::guarantorRequired($get)),
                        Select::make('grace_cycles')
                            ->label(__('Grace cycles before first repayment'))
                            ->options([
                                0 => __('None'),
                                1 => __('One cycle'),
                                2 => __('Two cycles'),
                            ])
                            ->required()
                            ->native(false),
                        Toggle::make('is_emergency')
                            ->label(__('Emergency loan'))
                            ->helperText(__('Bypasses standard queue and uses the emergency fund tier.')),
                        Textarea::make('purpose')
                            ->label(__('Purpose'))
                            ->rows(3)
                            ->maxLength(2000)
                            ->columnSpanFull(),
                        TextInput::make('witness1_name')
                            ->label(__('Witness 1 name'))
                            ->maxLength(255),
                        TextInput::make('witness1_phone')
                            ->label(__('Witness 1 phone'))
                            ->tel()
                            ->maxLength(50),
                        TextInput::make('witness2_name')
                            ->label(__('Witness 2 name'))
                            ->maxLength(255),
                        TextInput::make('witness2_phone')
                            ->label(__('Witness 2 phone'))
                            ->tel()
                            ->maxLength(50),
                    ]),
                Section::make(__('Funding'))
                    ->columns(2)
                    ->schema([
                        $strategyRadio,
                        $strategyFixed,
                        $excessDisposition,
                        $fundingPreview,
                    ]),
                Section::make(__('Approval preview'))
                    ->description(__('Estimated tier, fund split, and repayment period if you approve at the requested amount.'))
                    ->schema([
                        Placeholder::make('approval_schedule_preview')
                            ->hiddenLabel()
                            ->content(function (Get $get) use ($loanResolver): HtmlString {
                                $loan = $loanResolver();
                                $amount = (float) ($get('amount_requested') ?? $loan->amount_requested);

                                return LoanApprovalPreview::html($loan, $amount, (bool) ($get('is_emergency') ?? $loan->is_emergency));
                            })
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * Approved loan awaiting disbursement — summary only; disburse via header action.
     */
    public static function configureApprovedProcessingForm(Schema $schema, Closure $loanResolver): Schema
    {
        $currency = Setting::get('general', 'currency', 'USD');

        return $schema
            ->components([
                Section::make(__('Approved loan'))
                    ->description(__('Record disbursement using the Disburse action above when funds are released to the member.'))
                    ->schema([
                        Placeholder::make('approved_summary')
                            ->hiddenLabel()
                            ->content(function () use ($loanResolver, $currency): HtmlString {
                                $loan = $loanResolver();
                                $loan->loadMissing(['member', 'fundTier', 'loanTier']);
                                $remaining = $loan->remainingToDisburse();

                                return new HtmlString(
                                    '<div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">'
                                    .self::snapshotRow(__('Member'), (string) ($loan->member?->name ?? '—'))
                                    .self::snapshotRow(__('Approved amount'), MoneyDisplay::format((float) $loan->amount_approved, $currency) ?? '—')
                                    .self::snapshotRow(__('Remaining to disburse'), MoneyDisplay::format($remaining, $currency) ?? '—')
                                    .self::snapshotRow(__('Fund tier'), (string) ($loan->fundTier?->label ?? '—'))
                                    .self::snapshotRow(__('Loan tier'), (string) ($loan->loanTier?->label ?? '—'))
                                    .self::snapshotRow(__('Approved at'), $loan->approved_at?->toDayDateTimeString() ?? '—')
                                    .'</div>'
                                );
                            })
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    private static function memberResolver(): Closure
    {
        return fn (Get $get): ?Member => self::resolveMember($get);
    }

    private static function resolveMember(Get $get): ?Member
    {
        $memberId = $get('member_id');

        if (blank($memberId)) {
            return null;
        }

        return Member::query()->with('fundAccount')->find($memberId);
    }

    private static function guarantorRequired(Get $get): bool
    {
        $member = self::resolveMember($get);

        if ($member === null) {
            return false;
        }

        return LoanSettings::guarantorRequiredForAmount(
            $member,
            (float) ($get('amount_requested') ?? 0),
            (string) ($get('funding_strategy') ?? LoanFundingStrategy::MEMBER_FUND_TOPUP),
        );
    }

    private static function snapshotRow(string $label, string $value): string
    {
        return '<div><p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">'.e($label).'</p>'
            .'<p class="mt-0.5 text-sm font-semibold tabular-nums text-gray-900 dark:text-white">'.e($value).'</p></div>';
    }
}
