<?php

declare(strict_types=1);

namespace App\Filament\Member\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Member\Support\MemberNavigation;
use App\Filament\Member\Support\SubmitSupportRequestAction;
use App\Filament\Member\Widgets\MyMemberRequestsTableWidget;
use App\Support\Tenant\CurrentMember;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class SupportPage extends Page
{
    use TranslatesPageNavigationLabel;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $navigationLabel = 'Support & requests';

    protected static string|\UnitEnum|null $navigationGroup = MemberNavigation::GROUP_SELF_SERVICE;

    protected static ?int $navigationSort = MemberNavigation::SORT_SUPPORT;

    protected static ?string $slug = 'support';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.member.pages.support';

    public static function canAccess(): bool
    {
        return CurrentMember::get() !== null;
    }

    public function getTitle(): string
    {
        return __('Support & requests');
    }

    /**
     * @return array<class-string>
     */
    protected function getFooterWidgets(): array
    {
        return [
            MyMemberRequestsTableWidget::class,
        ];
    }

    public function getFooterWidgetsColumns(): int|array
    {
        return 1;
    }

    protected function getHeaderActions(): array
    {
        return [
            SubmitSupportRequestAction::make(),
        ];
    }
}
