<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\Members\Pages;

use App\Filament\Concerns\RefreshesResourceRecord;
use App\Filament\Support\MemberFilamentActions;
use App\Filament\Tenant\Resources\Members\Concerns\InteractsWithMemberContributionHeaderActions;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Filament\Tenant\Resources\Members\Schemas\MemberViewInfolist;
use App\Models\Tenant\Member;
use App\Services\Loans\LoanDelinquencyService;
use App\Services\MemberWorkspaceSummaryService;
use App\Support\ArabicDisplaySettings;
use App\Support\ArabicTypography;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

class ViewMember extends ViewRecord
{
    use InteractsWithMemberContributionHeaderActions;
    use RefreshesResourceRecord;

    protected static string $resource = MemberResource::class;

    /**
     * @var array<string, mixed>|null
     */
    private ?array $workspaceSummaryCache = null;

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

        $status = Member::statusOptions()[(string) $this->record->status]
            ?? ucfirst((string) $this->record->status);

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

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            SchemaView::make('filament.tenant.pages.member-workspace-summary')
                ->viewData(fn (): array => [
                    'summary' => $this->workspaceSummary(),
                ]),
            $this->getRelationManagersContentComponent(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function workspaceSummary(): array
    {
        assert($this->record instanceof Member);

        return $this->workspaceSummaryCache ??= app(MemberWorkspaceSummaryService::class)
            ->summary($this->record);
    }

    protected function getHeaderActions(): array
    {
        return [
            ...MemberFilamentActions::forMemberEditHeader(
                $this->buildMemberAllocateDependentsAction(),
            ),
            EditAction::make()
                ->label(__('Edit profile')),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return MemberViewInfolist::configure($schema);
    }

    protected function resolveRecord(int|string $key): Model
    {
        /** @var Member $record */
        $record = parent::resolveRecord($key);

        return $record->load([
            'parent',
            'user',
            'cashAccount',
            'fundAccount',
        ]);
    }

    #[On('refresh-member-detail-insights')]
    public function refreshMemberFromInsights(int $memberId): void
    {
        if ((int) $this->getRecord()->getKey() !== $memberId) {
            return;
        }

        MemberWorkspaceSummaryService::forgetCached($memberId);
        app(LoanDelinquencyService::class)->forgetMemberRuntimeCaches($memberId);

        $this->workspaceSummaryCache = null;

        $this->refreshResolvedRecord();
    }
}
