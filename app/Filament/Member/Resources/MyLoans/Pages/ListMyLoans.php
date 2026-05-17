<?php

declare(strict_types=1);

namespace App\Filament\Member\Resources\MyLoans\Pages;

use App\Filament\Member\Pages\LoanCalculator;
use App\Filament\Member\Resources\MyLoans\MyLoanResource;
use App\Filament\Member\Widgets\MemberLoanInsightsWidget;
use App\Models\Tenant\LoanTier;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\Loans\LoanLifecycleService;
use App\Services\LoanService;
use App\Support\LoanSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\HtmlString;
use Throwable;

class ListMyLoans extends ListRecords
{
    protected static string $resource = MyLoanResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            MemberLoanInsightsWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    public function getSubheading(): ?string
    {
        return __('Track your applications, active loan, and repayment progress.');
    }

    protected function getHeaderActions(): array
    {
        $member = auth('tenant')->user()?->member;

        return [
            Action::make('calculator')
                ->label(__('Loan calculator'))
                ->icon('heroicon-o-calculator')
                ->url(LoanCalculator::getUrl())
                ->color('gray'),
            Action::make('applyForLoan')
                ->label(__('Apply for loan'))
                ->icon('heroicon-o-plus')
                ->color('primary')
                ->visible(fn (): bool => $member !== null && app(LoanService::class)->checkEligibility($member)['eligible'])
                ->modalHeading(__('Loan application'))
                ->modalDescription(__('Submit a request for admin review. You will be notified when it is approved or rejected.'))
                ->schema([
                    Placeholder::make('max_hint')
                        ->label(__('Maximum amount'))
                        ->content(function () use ($member): string {
                            $max = LoanSettings::maxLoanAmountForMember($member?->getFundBalance() ?? 0);
                            $currency = Setting::get('general', 'currency', 'USD');

                            return number_format($max, 2).' '.$currency;
                        }),
                    TextInput::make('amount')
                        ->label(__('Amount'))
                        ->numeric()
                        ->required()
                        ->minValue(1)
                        ->live(onBlur: true),
                    Select::make('guarantor_member_id')
                        ->label(__('Guarantor (optional)'))
                        ->options(
                            Member::query()
                                ->active()
                                ->whereKeyNot($member?->id)
                                ->pluck('name', 'id')
                        )
                        ->searchable()
                        ->nullable(),
                    Toggle::make('has_grace_cycle')
                        ->label(__('Request grace cycle'))
                        ->default(true),
                    Placeholder::make('preview')
                        ->label(__('Estimated installment'))
                        ->content(function (Get $get): HtmlString {
                            $amount = (float) ($get('amount') ?? 0);
                            $tier = LoanTier::forAmount($amount);
                            $install = (float) ($tier?->min_monthly_installment ?? 0);
                            $currency = Setting::get('general', 'currency', 'USD');

                            return new HtmlString('<span class="font-semibold">'.e($install > 0 ? number_format($install, 2).' '.$currency.' / '.__('month') : '—').'</span>');
                        }),
                    Textarea::make('purpose')
                        ->label(__('Purpose'))
                        ->required()
                        ->rows(3)
                        ->maxLength(2000),
                ])
                ->action(function (array $data, LoanLifecycleService $lifecycle) use ($member): void {
                    if (! $member instanceof Member) {
                        return;
                    }

                    try {
                        $loan = $lifecycle->applyForLoan(
                            $member,
                            (float) $data['amount'],
                            (string) $data['purpose'],
                            filled($data['guarantor_member_id'] ?? null) ? (int) $data['guarantor_member_id'] : null,
                            false,
                            (bool) ($data['has_grace_cycle'] ?? true),
                        );

                        Notification::make()
                            ->title(__('Loan application submitted'))
                            ->body(__('Reference #:id', ['id' => $loan->id]))
                            ->success()
                            ->send();
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title(__('Application failed'))
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}
