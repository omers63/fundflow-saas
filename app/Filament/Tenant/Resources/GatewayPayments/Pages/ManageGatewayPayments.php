<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\GatewayPayments\Pages;

use App\Filament\Support\TableHeaderIconAction;
use App\Filament\Tenant\Resources\GatewayPayments\GatewayPaymentResource;
use App\Models\Tenant\User;
use App\Services\Gateway\SadadPaymentFileImportService;
use App\Support\SadadSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageGatewayPayments extends ManageRecords
{
    protected static string $resource = GatewayPaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            TableHeaderIconAction::apply(
                Action::make('importSadadFile')
                    ->label(__('Import SADAD file'))
                    ->icon(Heroicon::OutlinedArrowUpTray)
                    ->visible(fn(): bool => SadadSettings::enabled())
                    ->schema([
                        Textarea::make('contents')
                            ->label(__('Settlement CSV'))
                            ->required()
                            ->rows(10)
                            ->helperText(__('Columns: provider_ref,amount,status,paid_at,member_number')),
                    ])
                    ->action(function (array $data): void {
                        /** @var User|null $actor */
                        $actor = auth('tenant')->user();
                        $result = app(SadadPaymentFileImportService::class)->import(
                            (string) $data['contents'],
                            $actor,
                        );

                        Notification::make()
                            ->title(__('SADAD file imported'))
                            ->body(__('Paid :paid · Failed :failed · Skipped :skipped', [
                                'paid' => $result['paid'],
                                'failed' => $result['failed'],
                                'skipped' => $result['skipped'],
                            ]))
                            ->success()
                            ->send();
                    }),
            ),
        ];
    }
}
