<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\Members\Pages;

use App\Filament\Concerns\RefreshesResourceRecord;
use App\Filament\Support\MemberFilamentActions;
use App\Filament\Tenant\Resources\Members\Concerns\InteractsWithMemberContributionHeaderActions;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Filament\Tenant\Resources\Members\Schemas\MemberViewInfolist;
use App\Filament\Tenant\Widgets\MemberDetailInsightsWidget;
use App\Models\Tenant\Member;
use App\Support\ArabicDisplaySettings;
use App\Support\ArabicTypography;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\On;

class ViewMember extends ViewRecord
{
    use InteractsWithMemberContributionHeaderActions;
    use RefreshesResourceRecord;

    protected static string $resource = MemberResource::class;

    public function getTitle(): string
    {
        return __('Member');
    }

    public function getHeading(): string|Htmlable
    {
        assert($this->record instanceof Member);

        if (ArabicDisplaySettings::enhancedNameStyle() && ArabicTypography::containsArabic($this->record->name)) {
            return ArabicTypography::display($this->record->name);
        }

        return $this->record->name;
    }

    public function getSubheading(): ?string
    {
        assert($this->record instanceof Member);

        $status = $this->record->adminStatusLabel();

        return __(':number · :status', [
            'number' => $this->record->member_number,
            'status' => $status,
        ]);
    }

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return __('Profile');
    }

    public function getContentTabIcon(): string|\BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedUser;
    }

    /**
     * @return array<string>
     */
    public function getPageClasses(): array
    {
        return [
            ...parent::getPageClasses(),
            'fi-page-member-detail',
            'ff-tenant-member-detail',
        ];
    }

    /**
     * @return array<int, class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            MemberDetailInsightsWidget::class,
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
            'memberId' => $this->getRecord()->getKey(),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->buildMemberContributeAction(),
            $this->buildMemberAllocateDependentsAction(),
            ...MemberFilamentActions::forMemberEditHeader(),
            EditAction::make()
                ->label(__('Edit profile')),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return MemberViewInfolist::configure($schema);
    }

    #[On('refresh-member-detail-insights')]
    public function refreshMemberFromInsights(int $memberId): void
    {
        if ((int) $this->getRecord()->getKey() !== $memberId) {
            return;
        }

        $this->refreshResolvedRecord();
    }
}
