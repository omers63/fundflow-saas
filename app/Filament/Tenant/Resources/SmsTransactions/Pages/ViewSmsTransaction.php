<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\SmsTransactions\Pages;

use App\Filament\Tenant\Resources\SmsTransactions\SmsTransactionResource;
use App\Models\Tenant\Member;
use App\Models\Tenant\SmsTransaction;
use App\Services\AccountingService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewSmsTransaction extends ViewRecord
{
    protected static string $resource = SmsTransactionResource::class;

    protected function getHeaderActions(): array
    {
        $memberOptions = fn (): array => Member::query()
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Member $member): array => [
                $member->id => trim(($member->member_number ? $member->member_number.' — ' : '').$member->name),
            ])
            ->all();

        return [
            Action::make('postToCash')
                ->label(__('Post to cash'))
                ->icon('heroicon-o-arrow-right-circle')
                ->color('primary')
                ->visible(fn (): bool => ! $this->getRecord()->isPosted())
                ->fillForm(fn (): array => ['member_id' => $this->getRecord()->member_id])
                ->schema([
                    Select::make('member_id')
                        ->label(__('Post for member'))
                        ->options($memberOptions)
                        ->searchable()
                        ->required(),
                ])
                ->action(function (array $data): void {
                    /** @var SmsTransaction $record */
                    $record = $this->getRecord();
                    $member = Member::query()->findOrFail($data['member_id']);
                    app(AccountingService::class)->postSmsTransactionToCash($record, $member);

                    Notification::make()
                        ->title(__('Posted to cash account'))
                        ->body(__('SMS transaction posted for :name.', ['name' => $member->name]))
                        ->success()
                        ->send();

                    $this->refreshFormData(['posted_at', 'posted_by', 'member_id']);
                }),
            DeleteAction::make()
                ->modalDescription(__('Soft-deletes this SMS import row. If it was posted to cash, matching ledger lines are reversed first.'))
                ->using(function (SmsTransaction $record): bool {
                    app(AccountingService::class)->safeDeleteSmsTransaction($record);

                    return true;
                })
                ->successRedirectUrl(SmsTransactionResource::getUrl('index')),
        ];
    }
}
