<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\ReconciliationExceptions\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Tenant\Resources\ReconciliationExceptions\ReconciliationExceptionResource;
use App\Services\ReconciliationService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListReconciliationExceptions extends ListRecords
{
    use TranslatesPageNavigationLabel;

    protected static string $resource = ReconciliationExceptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('run_nightly')
                ->label(__('Run reconciliation batch'))
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->action(function (ReconciliationService $reconciliation): void {
                    $result = $reconciliation->runNightlyBatch();

                    Notification::make()
                        ->title($result['halted']
                            ? __('Reconciliation halted')
                            : __('Reconciliation complete'))
                        ->body(__('Raised: :raised | Resolved: :resolved', [
                            'raised' => $result['raised'],
                            'resolved' => $result['resolved'],
                        ]))
                        ->color($result['halted'] ? 'danger' : 'success')
                        ->send();

                    $this->resetTable();
                }),
        ];
    }
}
