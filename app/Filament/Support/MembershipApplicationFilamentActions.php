<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Tenant\Resources\MembershipApplications\MembershipApplicationResource;
use App\Models\Tenant\MembershipApplication;
use App\Services\MembershipApplicationApprovalService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;

final class MembershipApplicationFilamentActions
{
    public static function approve(): Action
    {
        return Action::make('approve')
            ->label(__('Approve'))
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (MembershipApplication $record): bool => $record->status === 'pending')
            ->action(function (MembershipApplication $record, Action $action, Component $livewire): void {
                $member = null;

                if (
                    ! ActionModalFailure::attempt(
                        $action,
                        function () use ($record, &$member): void {
                            $member = app(MembershipApplicationApprovalService::class)->approve($record);
                        },
                        __('Approval blocked'),
                    )
                ) {
                    return;
                }

                Notification::make()
                    ->title(__('Member :name created from application', ['name' => $member->name]))
                    ->success()
                    ->send();

                MembershipApplicationResource::dispatchInsightsRefresh($livewire);
            });
    }

    public static function reject(): Action
    {
        return Action::make('reject')
            ->label(__('Reject'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (MembershipApplication $record): bool => $record->status === 'pending')
            ->action(function (MembershipApplication $record, Component $livewire): void {
                app(MembershipApplicationApprovalService::class)->reject($record);

                Notification::make()->title(__('Application rejected'))->warning()->send();

                MembershipApplicationResource::dispatchInsightsRefresh($livewire);
            });
    }

    public static function approveBulk(): BulkAction
    {
        return BulkAction::make('approveSelected')
            ->label(__('Approve'))
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->action(function (Collection $records, Component $livewire): void {
                $result = app(MembershipApplicationApprovalService::class)->approveMany($records);

                $approvedCount = count($result['members']);
                $failureCount = count($result['failures']);

                if ($approvedCount > 0) {
                    Notification::make()
                        ->title(__(':count application(s) approved', ['count' => $approvedCount]))
                        ->success()
                        ->send();
                }

                if ($failureCount > 0) {
                    $summary = collect($result['failures'])
                        ->take(3)
                        ->map(fn (array $failure): string => "{$failure['name']}: {$failure['message']}")
                        ->implode("\n");

                    if ($failureCount > 3) {
                        $summary .= "\n".__('…and :count more.', ['count' => $failureCount - 3]);
                    }

                    Notification::make()
                        ->title(__('Approval blocked for :count application(s)', ['count' => $failureCount]))
                        ->body($summary)
                        ->danger()
                        ->persistent()
                        ->send();
                }

                if ($approvedCount === 0 && $failureCount === 0) {
                    Notification::make()
                        ->title(__('No pending applications were selected'))
                        ->warning()
                        ->send();
                }

                MembershipApplicationResource::dispatchInsightsRefresh($livewire);
            });
    }

    public static function rejectBulk(): BulkAction
    {
        return BulkAction::make('rejectSelected')
            ->label(__('Reject'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->action(function (Collection $records, Component $livewire): void {
                $rejected = 0;

                foreach ($records as $record) {
                    if ($record->status !== 'pending') {
                        continue;
                    }

                    app(MembershipApplicationApprovalService::class)->reject($record);
                    $rejected++;
                }

                Notification::make()
                    ->title(__(':count application(s) rejected', ['count' => $rejected]))
                    ->warning()
                    ->send();

                MembershipApplicationResource::dispatchInsightsRefresh($livewire);
            });
    }
}
