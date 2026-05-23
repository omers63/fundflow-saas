<?php

declare(strict_types=1);

namespace App\Filament\Member\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Member\Resources\MyLoans\MyLoanResource;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanTier;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\Loans\LoanLifecycleService;
use App\Services\LoanService;
use App\Support\LoanSettings;
use App\Support\Tenant\CurrentMember;
use BackedEnum;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Throwable;

class ApplyForLoan extends Page implements HasForms
{
    use InteractsWithForms;
    use TranslatesPageNavigationLabel;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentPlus;

    protected static ?string $navigationLabel = 'Apply for loan';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'apply-for-loan';

    protected string $view = 'filament.member.pages.apply-for-loan';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $member = CurrentMember::get();
        $eligibility = $member ? app(LoanService::class)->checkEligibility($member) : ['eligible' => false];

        if ($member && $member->loans()->where('status', 'pending')->exists()) {
            Notification::make()
                ->title(__('Application already pending'))
                ->body(__('You already have a loan application awaiting review.'))
                ->warning()
                ->send();

            $this->redirect(MyLoanResource::getUrl('index'));

            return;
        }

        if (! ($eligibility['eligible'] ?? false)) {
            Notification::make()
                ->title(__('Not eligible to apply'))
                ->body($eligibility['reason'] ?? __('You cannot apply for a loan at this time.'))
                ->warning()
                ->send();

            $this->redirect(MyLoanResource::getUrl('index'));

            return;
        }

        $this->form->fill([
            'has_grace_cycle' => true,
        ]);
    }

    public function getTitle(): string
    {
        return __('Apply for loan');
    }

    public function getSubheading(): ?string
    {
        return __('Amount, purpose, and review — submit when you are ready.');
    }

    public function form(Schema $schema): Schema
    {
        $member = CurrentMember::get();
        $currency = Setting::get('general', 'currency', 'USD');

        return $schema
            ->components([
                Wizard::make([
                    Step::make(__('Amount'))
                        ->icon(Heroicon::OutlinedBanknotes)
                        ->schema([
                            Placeholder::make('max_hint')
                                ->label(__('Maximum amount'))
                                ->content(function () use ($member, $currency): string {
                                    $max = LoanSettings::maxLoanAmountForMember($member?->getFundBalance() ?? 0);

                                    return number_format($max, 2).' '.$currency;
                                }),
                            TextInput::make('amount')
                                ->label(__('Loan amount'))
                                ->numeric()
                                ->required()
                                ->minValue(1)
                                ->live(onBlur: true)
                                ->suffix($currency),
                            Select::make('guarantor_member_id')
                                ->label(__('Guarantor'))
                                ->helperText(__('Required when the amount exceeds your fund balance.'))
                                ->options(
                                    Member::query()
                                        ->active()
                                        ->when($member, fn ($q) => $q->whereKeyNot($member->id))
                                        ->pluck('name', 'id')
                                )
                                ->searchable()
                                ->required(fn (Get $get): bool => $member && LoanSettings::guarantorRequiredForAmount($member, (float) ($get('amount') ?? 0)))
                                ->nullable(fn (Get $get): bool => ! $member || ! LoanSettings::guarantorRequiredForAmount($member, (float) ($get('amount') ?? 0))),
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
                        ]),
                    Step::make(__('Purpose'))
                        ->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)
                        ->schema([
                            Textarea::make('purpose')
                                ->label(__('Purpose of loan'))
                                ->required()
                                ->rows(5)
                                ->maxLength(2000)
                                ->helperText(__('Describe how you plan to use the funds.')),
                        ]),
                    Step::make(__('Witnesses'))
                        ->icon(Heroicon::OutlinedUserGroup)
                        ->schema([
                            TextInput::make('witness1_name')
                                ->label(__('Witness 1 name'))
                                ->maxLength(150),
                            TextInput::make('witness1_phone')
                                ->label(__('Witness 1 phone'))
                                ->tel()
                                ->maxLength(50),
                            TextInput::make('witness2_name')
                                ->label(__('Witness 2 name'))
                                ->maxLength(150),
                            TextInput::make('witness2_phone')
                                ->label(__('Witness 2 phone'))
                                ->tel()
                                ->maxLength(50),
                        ])
                        ->columns(2),
                    Step::make(__('Review'))
                        ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                        ->schema([
                            Placeholder::make('review_amount')
                                ->label(__('Amount'))
                                ->content(fn (Get $get): string => number_format((float) ($get('amount') ?? 0), 2).' '.$currency),
                            Placeholder::make('review_installment')
                                ->label(__('Estimated monthly installment'))
                                ->content(function (Get $get) use ($currency, $member): HtmlString {
                                    $amount = (float) ($get('amount') ?? 0);
                                    $tier = LoanTier::forAmount($amount);
                                    $install = (float) ($tier?->min_monthly_installment ?? 0);
                                    $fundBal = $member?->getFundBalance() ?? 0;
                                    $months = $tier
                                        ? Loan::computeInstallmentsCount($amount, $fundBal, $install, LoanSettings::settlementThreshold())
                                        : 0;
                                    $text = $install > 0
                                        ? number_format($install, 2).' '.$currency.' / '.__('month')
                                        : '—';
                                    if ($months > 0) {
                                        $text .= ' · '.trans_choice('~:count month|~:count months', $months, ['count' => $months]);
                                    }

                                    return new HtmlString('<span class="font-semibold">'.e($text).'</span>');
                                }),
                            Placeholder::make('review_purpose')
                                ->label(__('Purpose'))
                                ->content(fn (Get $get): HtmlString => new HtmlString(nl2br(e((string) ($get('purpose') ?? ''))))),
                        ]),
                ])
                    ->submitAction(new HtmlString(Blade::render(<<<'BLADE'
                        <x-filament::button type="button" wire:click="submitApplication" size="sm">
                            {{ __('Submit application') }}
                        </x-filament::button>
                    BLADE)))
                    ->contained(false),
            ])
            ->statePath('data');
    }

    public function submitApplication(LoanLifecycleService $lifecycle): void
    {
        $member = CurrentMember::get();

        if (! $member instanceof Member) {
            return;
        }

        $data = $this->form->getState();

        try {
            $loan = $lifecycle->applyForLoan(
                $member,
                (float) $data['amount'],
                (string) $data['purpose'],
                filled($data['guarantor_member_id'] ?? null) ? (int) $data['guarantor_member_id'] : null,
                false,
                ((int) ($data['grace_cycles'] ?? 1)) > 0,
                (int) ($data['grace_cycles'] ?? 1),
                filled($data['witness1_name'] ?? null) ? (string) $data['witness1_name'] : null,
                filled($data['witness1_phone'] ?? null) ? (string) $data['witness1_phone'] : null,
                filled($data['witness2_name'] ?? null) ? (string) $data['witness2_name'] : null,
                filled($data['witness2_phone'] ?? null) ? (string) $data['witness2_phone'] : null,
            );

            Notification::make()
                ->title(__('Loan application submitted'))
                ->body(__('Reference #:id', ['id' => $loan->id]))
                ->success()
                ->send();

            $this->redirect(MyLoanResource::getUrl('view', ['record' => $loan]));
        } catch (Throwable $exception) {
            Notification::make()
                ->title(__('Application failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }
}
