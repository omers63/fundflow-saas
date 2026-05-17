<?php

namespace App\Filament\Tenant\Resources\Accounts\Pages;

use App\Filament\Support\MemberAccountTableActions;
use App\Filament\Tenant\Resources\Accounts\AccountResource;
use App\Filament\Tenant\Widgets\AccountDetailInsightsWidget;
use App\Models\Tenant\Setting;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\TextEntry;
use Filament\Schemas\Schema;
use Livewire\Attributes\On;

class ViewAccount extends ViewRecord
{
    protected static string $resource = AccountResource::class;

    public function getHeading(): string
    {
        return $this->record->name;
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            MemberAccountTableActions::delete()
                ->successRedirectUrl(AccountResource::getUrl('index')),
        ];
    }

    /**
     * @return array<int, class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            AccountDetailInsightsWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    /**
     * @return array<string, mixed>
     */
    public function getWidgetData(): array
    {
        return [
            ...parent::getWidgetData(),
            'accountId' => $this->getRecord()->getKey(),
        ];
    }

    #[On('refresh-account-detail-insights')]
    public function refreshAccountFromLedger(int $accountId): void
    {
        if ((int) $this->getRecord()->getKey() !== $accountId) {
            return;
        }

        $this->refreshRecord();
    }

    public function schema(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Account Details'))
                    ->columns(4)
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('member.name')
                            ->label('Member'),
                        TextEntry::make('type')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'cash' => 'info',
                                'fund' => 'success',
                            }),
                        TextEntry::make('balance')
                            ->money(fn (): string => Setting::get('general', 'currency', 'USD')),
                    ]),
            ]);
    }
}
