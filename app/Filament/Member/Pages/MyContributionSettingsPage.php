<?php

declare(strict_types=1);

namespace App\Filament\Member\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Member\Resources\MyDependents\MyDependentResource;
use App\Filament\Member\Support\MemberNavigation;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Support\Tenant\CurrentMember;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class MyContributionSettingsPage extends Page
{
    use TranslatesPageNavigationLabel;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?string $navigationLabel = 'Contribution settings';

    protected static string|\UnitEnum|null $navigationGroup = MemberNavigation::GROUP_SETTINGS;

    protected static ?int $navigationSort = MemberNavigation::SORT_CONTRIBUTION_SETTINGS;

    protected static ?string $slug = 'contribution-settings';

    protected string $view = 'filament.member.pages.my-contribution-settings';

    public int $monthly_contribution_amount = 500;

    public static function canAccess(): bool
    {
        return CurrentMember::get() !== null;
    }

    public function getTitle(): string
    {
        return __('Contribution settings');
    }

    public function mount(): void
    {
        $member = CurrentMember::get();
        $this->monthly_contribution_amount = (int) ($member?->monthly_contribution_amount ?? 500);
    }

    protected function getHeaderActions(): array
    {
        $member = CurrentMember::get();
        $actions = [
            Action::make('save_allocation')
                ->label(__('Save allocation'))
                ->icon('heroicon-o-check-circle')
                ->color('primary')
                ->fillForm(['monthly_contribution_amount' => $this->monthly_contribution_amount])
                ->schema([
                    Select::make('monthly_contribution_amount')
                        ->label(__('Monthly contribution amount'))
                        ->options(Member::contributionAmountOptions())
                        ->required()
                        ->helperText(__('Choose your monthly contribution tier. Administrators are notified when you change it.')),
                ])
                ->action(function (array $data): void {
                    $member = CurrentMember::get();

                    if ($member === null) {
                        Notification::make()
                            ->title(__('Member record not found'))
                            ->danger()
                            ->send();

                        return;
                    }

                    $newAmount = (int) $data['monthly_contribution_amount'];
                    $oldAmount = (int) $member->monthly_contribution_amount;

                    if (! Member::isValidContributionAmount($newAmount)) {
                        Notification::make()
                            ->title(__('Invalid amount selected'))
                            ->danger()
                            ->send();

                        return;
                    }

                    if ($newAmount === $oldAmount) {
                        Notification::make()
                            ->title(__('No changes detected'))
                            ->info()
                            ->send();

                        return;
                    }

                    $member->update(['monthly_contribution_amount' => $newAmount]);

                    User::query()
                        ->where('is_admin', true)
                        ->each(function (User $admin) use ($member, $oldAmount, $newAmount): void {
                            Notification::make()
                                ->title(__('Member allocation updated'))
                                ->body(__('Member :name changed monthly contribution from :old to :new.', [
                                    'name' => $member->name,
                                    'old' => number_format($oldAmount),
                                    'new' => number_format($newAmount),
                                ]))
                                ->icon('heroicon-o-adjustments-horizontal')
                                ->iconColor('info')
                                ->sendToDatabase($admin);
                        });

                    Notification::make()
                        ->title(__('Allocation updated'))
                        ->body(__('Your monthly contribution amount has been saved.'))
                        ->success()
                        ->send();

                    $this->mount();
                }),
        ];

        if ($member !== null && $member->dependents()->exists()) {
            $actions[] = Action::make('family_requests')
                ->label(__('My dependents'))
                ->icon('heroicon-o-users')
                ->url(MyDependentResource::getUrl())
                ->color('gray');
        }

        return $actions;
    }
}
