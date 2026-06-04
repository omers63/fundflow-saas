<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Tenant\Resources\Loans\LoanResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Livewire\Component;

final class LoanEmiCollectionTableActions
{
    public static function notifyApplyOutcome(string $outcome, ?string $memberName = null, ?Action $action = null): void
    {
        $title = match ($outcome) {
            'collected' => __('EMI collected'),
            'partial' => __('Partial EMI collection'),
            'none' => __('Nothing to collect'),
            default => __('Could not collect'),
        };

        $body = match ($outcome) {
            'collected' => $memberName !== null
            ? __('Collected pending EMIs for :name.', ['name' => $memberName])
            : __('Collected pending EMIs.'),
            'partial' => __('Some installments remain — cash may have been insufficient for the full arrears stack.'),
            'no_cash' => __('Insufficient cash balance for pending EMIs.'),
            'none' => __('No collectable installments for this member.'),
            default => $outcome,
        };

        if (in_array($outcome, ['collected', 'partial'], true)) {
            Notification::make()
                ->title($title)
                ->body($body)
                ->color($outcome === 'collected' ? 'success' : 'warning')
                ->send();

            return;
        }

        if ($action !== null) {
            ActionModalFailure::present($action, $body, $title);
        }

        Notification::make()
            ->title($title)
            ->body($body)
            ->warning()
            ->send();
    }

    public static function refreshViews(Component $livewire): void
    {
        LoanResource::dispatchInsightsRefresh($livewire);

        if (method_exists($livewire, 'resetTable')) {
            $livewire->resetTable();
        }
    }
}
