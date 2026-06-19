<?php

declare(strict_types=1);

namespace App\Filament\Member\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Member\Support\MemberNavigation;
use App\Services\MemberActivityFeedService;
use App\Support\Tenant\CurrentMember;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

class MemberActivityPage extends Page
{
    use TranslatesPageNavigationLabel;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Transactions';

    protected static string|\UnitEnum|null $navigationGroup = MemberNavigation::GROUP_HISTORY;

    protected static ?int $navigationSort = MemberNavigation::SORT_ACTIVITY;

    protected static ?string $slug = 'activity';

    protected string $view = 'filament.member.pages.member-activity';

    #[Url(as: 'filter', except: MemberActivityFeedService::FILTER_ALL)]
    public string $activeFilter = MemberActivityFeedService::FILTER_ALL;

    public static function canAccess(): bool
    {
        return CurrentMember::get() !== null;
    }

    public function getTitle(): string
    {
        return __('Transactions');
    }

    public function getSubheading(): ?string
    {
        return __('Cash, fund, and loan transactions across your accounts.');
    }

    public function setFilter(string $filter): void
    {
        $allowed = collect(app(MemberActivityFeedService::class)->filterOptions())
            ->pluck('key')
            ->all();

        if (in_array($filter, $allowed, true)) {
            $this->activeFilter = $filter;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'filters' => app(MemberActivityFeedService::class)->filterOptions(),
            'exportUrl' => route('tenant.member.activity.export'),
        ];
    }
}
