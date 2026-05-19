<?php

declare(strict_types=1);

namespace App\Filament\Member\Resources\MyDependents\Pages;

use App\Filament\Member\Resources\MyDependents\MyDependentResource;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\ContributionCycleService;
use App\Support\Tenant\CurrentMember;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewMyDependent extends ViewRecord
{
    protected static string $resource = MyDependentResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $parent = CurrentMember::get();
        if (
            $parent === null
            || ! $parent->isParent()
            || (int) $this->record->parent_member_id !== (int) $parent->id
        ) {
            abort(403);
        }
    }

    public function getHeading(): string
    {
        return $this->record->name;
    }

    public function getSubheading(): ?string
    {
        return $this->record->member_number;
    }

    protected function getHeaderActions(): array
    {
        /** @var Member $dependent */
        $dependent = $this->record;

        return [
            Action::make('switchToPortal')
                ->label(__('Switch to portal'))
                ->icon('heroicon-o-arrow-right-end-on-rectangle')
                ->color('primary')
                ->requiresConfirmation()
                ->modalDescription(__('You will switch into this dependent portal.'))
                ->url(route('tenant.member.dependents.impersonate', ['dependent' => $dependent]))
                ->visible(fn (): bool => ! in_array($dependent->status, Member::PORTAL_BLOCKED_STATUSES, true)),
        ];
    }

    public function schema(Schema $schema): Schema
    {
        $currency = Setting::get('general', 'currency', 'USD');
        [$openMonth, $openYear] = app(ContributionCycleService::class)->currentOpenPeriod();
        $cycles = app(ContributionCycleService::class);

        /** @var Member $dependent */
        $dependent = $this->record;

        $openContribution = Contribution::query()
            ->where('member_id', $dependent->id)
            ->forPeriod($openMonth, $openYear)
            ->first();

        return $schema->schema([
            Section::make(__('Profile'))
                ->columns(2)
                ->schema([
                    TextEntry::make('member_number')
                        ->label(__('Member number')),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => Member::statusOptions()[$state] ?? $state)
                        ->color(fn (string $state): string => Member::statusBadgeColor($state)),
                    TextEntry::make('email')
                        ->label(__('Contact email')),
                    TextEntry::make('joined_at')
                        ->date()
                        ->placeholder('—'),
                    TextEntry::make('monthly_contribution_amount')
                        ->label(__('Monthly contribution'))
                        ->money($currency),
                    TextEntry::make('user.email')
                        ->label(__('Login email'))
                        ->placeholder('—'),
                ]),
            Section::make(__('Balances'))
                ->columns(2)
                ->schema([
                    TextEntry::make('cash_display')
                        ->label(__('Cash balance'))
                        ->state(fn (): string => number_format($dependent->getCashBalance(), 2).' '.$currency),
                    TextEntry::make('fund_display')
                        ->label(__('Fund balance'))
                        ->state(fn (): string => number_format($dependent->getFundBalance(), 2).' '.$currency),
                ]),
            Section::make(__('Open contribution cycle'))
                ->columns(2)
                ->schema([
                    TextEntry::make('open_period')
                        ->label(__('Period'))
                        ->state($cycles->periodLabel($openMonth, $openYear)),
                    TextEntry::make('open_cycle_status')
                        ->label(__('Status'))
                        ->badge()
                        ->state(function () use ($openContribution): string {
                            if ($openContribution === null) {
                                return __('Not started');
                            }

                            return match ($openContribution->status) {
                                'posted' => __('Posted'),
                                'pending' => __('Pending'),
                                'failed' => __('Failed'),
                                default => ucfirst($openContribution->status),
                            };
                        })
                        ->color(match ($openContribution?->status) {
                            'posted' => 'success',
                            'pending' => 'warning',
                            'failed' => 'danger',
                            default => 'gray',
                        }),
                    TextEntry::make('cycle_window')
                        ->label(__('Cycle window'))
                        ->state($cycles->cycleWindowDescription($openMonth, $openYear))
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
