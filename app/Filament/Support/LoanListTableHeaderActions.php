<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Tenant\Resources\LoanEligibilityOverrides\LoanEligibilityOverrideResource;
use App\Models\Tenant\FundTier;
use App\Services\Loans\LoanQueueOrderingService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

final class LoanListTableHeaderActions
{
    /**
     * @return list<Action|ActionGroup>
     */
    public static function portfolio(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /**
     * @return list<Action|ActionGroup>
     */
    public static function delinquency(): array
    {
        return [
            self::delinquencyToolsGroup(),
        ];
    }

    /**
     * @return list<Action|ActionGroup>
     */
    public static function eligibilityReviews(): array
    {
        return [
            self::loanOverridesAction(),
        ];
    }

    /**
     * @return list<Action>
     */
    public static function queue(): array
    {
        return [
            Action::make('resequence')
                ->label(__('Resequence queues'))
                ->icon('heroicon-o-arrows-up-down')
                ->requiresConfirmation()
                ->action(function (): void {
                    foreach (FundTier::query()->where('is_active', true)->pluck('id') as $tierId) {
                        LoanQueueOrderingService::resequenceFundTier((int) $tierId);
                    }

                    Notification::make()
                        ->title(__('Queues resequenced'))
                        ->success()
                        ->send();
                }),
        ];
    }

    public static function delinquencyToolsGroup(): ActionGroup
    {
        return ActionGroup::make(LoanDelinquencyHeaderActions::make())
            ->label(__('Delinquency tools'))
            ->icon('heroicon-o-exclamation-triangle')
            ->color('gray')
            ->button();
    }

    public static function loanOverridesAction(): Action
    {
        return Action::make('loanOverrides')
            ->label(__('Loan overrides'))
            ->icon(Heroicon::OutlinedShieldCheck)
            ->url(fn (): string => LoanEligibilityOverrideResource::getUrl('index'));
    }
}
