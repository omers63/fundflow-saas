<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Models\Tenant\Member;
use App\Services\Loans\LoanDelinquencyService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Livewire\Component;

final class MemberDelinquencyActions
{
    /**
     * @return list<Action>
     */
    public static function forMemberListRow(): array
    {
        return [];
    }

    /**
     * @return list<Action>
     */
    public static function forMemberEditHeaderNested(): array
    {
        return [
            self::checkArrears(),
            self::openArrearsWorkspace(),
        ];
    }

    /**
     * @return list<BulkActionGroup>
     */
    public static function forMemberListBulkGroups(): array
    {
        return [
            BulkActionGroup::make([
                self::checkArrearsBulk(),
            ])->label(__('Arrears')),
        ];
    }

    /**
     * @return list<BulkAction>
     */
    public static function forMemberListBulk(): array
    {
        return [
            self::checkArrearsBulk(),
        ];
    }

    public static function checkArrears(): Action
    {
        return Action::make('checkMemberArrears')
            ->label(__('Check arrears'))
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->visible(fn(Member $record): bool => $record->status === 'active')
            ->action(function (Member $record, LoanDelinquencyService $delinquency, Component $livewire): void {
                $result = $delinquency->syncMemberDelinquencyStatusForMember($record);

                if ($result['delinquent_count'] === 0 && $result['cleared_count'] === 0) {
                    Notification::make()
                        ->title(__('No result'))
                        ->body(__('Arrears check applies only to active members.'))
                        ->info()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title($delinquency->isDelinquent($record->fresh()) ? __('Arrears') : __('Clear'))
                    ->body($delinquency->isDelinquent($record->fresh())
                        ? __('Member breaches the delinquency policy while status remains active.')
                        : __('Member does not breach the delinquency policy.'))
                    ->success()
                    ->send();

                self::refreshMembersList($livewire);
            });
    }

    public static function openArrearsWorkspace(): Action
    {
        return Action::make('openArrearsWorkspace')
            ->label(__('Open arrears'))
            ->icon('heroicon-o-arrow-top-right-on-square')
            ->color('gray')
            ->visible(fn(Member $record, LoanDelinquencyService $delinquency): bool => $delinquency->isDelinquent($record)
                || $delinquency->memberHasArrears($record))
            ->url(function (Member $record, LoanDelinquencyService $delinquency): string {
                $summary = $delinquency->memberArrearsSummary($record);

                if (count($summary['unpaid_contribution_periods']) > 0) {
                    return ContributionResource::arrearsUrlForMember($record);
                }

                if ($summary['overdue_installment_count'] > 0) {
                    return LoanResource::overdueInstallmentsUrlForMember($record);
                }

                return MemberResource::getUrl('view', ['record' => $record]);
            });
    }

    public static function checkArrearsBulk(): BulkAction
    {
        return BulkAction::make('checkArrearsSelected')
            ->label(__('Check arrears'))
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->requiresConfirmation()
            ->action(function ($records, LoanDelinquencyService $delinquency, Component $livewire): void {
                $withArrears = 0;
                $clear = 0;

                foreach ($records as $record) {
                    if (!$record instanceof Member || $record->status !== 'active') {
                        continue;
                    }

                    $result = $delinquency->syncMemberDelinquencyStatusForMember($record);
                    $withArrears += $result['delinquent_count'];
                    $clear += $result['cleared_count'];
                }

                Notification::make()
                    ->title(__('Arrears check complete'))
                    ->body(__('With arrears: :with · Clear: :clear', [
                        'with' => $withArrears,
                        'clear' => $clear,
                    ]))
                    ->success()
                    ->send();

                self::refreshMembersList($livewire);
            });
    }

    private static function refreshMembersList(Component $livewire): void
    {
        MemberResource::dispatchInsightsRefresh($livewire);

        if (method_exists($livewire, 'resetTable')) {
            $livewire->resetTable();
        }
    }
}
