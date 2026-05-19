<?php

declare(strict_types=1);

namespace App\Filament\Member\Resources\MyAccounts\Pages;

use App\Filament\Member\Resources\MyAccounts\MyAccountResource;
use App\Filament\Member\Widgets\MemberMyAccountDetailInsightsWidget;
use App\Models\Tenant\Setting;
use App\Support\Tenant\CurrentMember;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\TextEntry;
use Filament\Schemas\Schema;

class ViewMyAccount extends ViewRecord
{
    protected static string $resource = MyAccountResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $member = CurrentMember::get();
        if ($member === null || (int) $this->record->member_id !== (int) $member->id) {
            abort(403);
        }
    }

    public function getSubheading(): ?string
    {
        return match ($this->record->type) {
            'cash' => __('Available cash for contributions and repayments'),
            'fund' => __('Your long-term fund savings ledger'),
            default => null,
        };
    }

    /**
     * @return array<class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            MemberMyAccountDetailInsightsWidget::class,
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

    public function getHeading(): string
    {
        return $this->record->name;
    }

    public function schema(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Account Details'))
                    ->columns(3)
                    ->schema([
                        TextEntry::make('name'),
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
