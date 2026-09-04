<?php

namespace App\Filament\Tenant\Resources\Members\Pages;

use App\Filament\Concerns\RefreshesResourceRecord;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Models\Tenant\Member;
use App\Services\MemberWorkspaceSummaryService;
use App\Services\Tenant\HouseholdMemberService;
use App\Support\ArabicDisplaySettings;
use App\Support\ArabicTypography;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

class EditMember extends EditRecord
{
    use RefreshesResourceRecord;

    protected static string $resource = MemberResource::class;

    /**
     * @var array<string, mixed>|null
     */
    private ?array $workspaceSummaryCache = null;

    public function getTitle(): string
    {
        return __('Edit profile');
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
        return __('Update membership details, contribution amount, and household linkage.');
    }

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return false;
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
            'fi-page-member-profile-edit',
            'ff-tenant-member-profile-edit',
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            SchemaView::make('filament.tenant.pages.member-workspace-summary')
                ->viewData(fn (): array => [
                    'summary' => $this->workspaceSummary(),
                ]),
            $this->getFormContentComponent(),
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
            Action::make('backToWorkspace')
                ->label(__('Back to workspace'))
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(fn (): string => MemberResource::getUrl('view', ['record' => $this->record])),
        ];
    }

    protected function afterSave(): void
    {
        assert($this->record instanceof Member);

        MemberWorkspaceSummaryService::forgetCached((int) $this->record->id);
        MemberResource::dispatchMemberDetailInsightsRefresh($this);

        $this->redirect(MemberResource::getUrl('view', ['record' => $this->record]));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        assert($record instanceof Member);

        $previousParentId = $record->parent_member_id;
        $newParentId = $data['parent_member_id'] ?? null;

        $record->update(collect($data)->except(['parent_member_id'])->all());

        $householdMembers = app(HouseholdMemberService::class);

        if ($newParentId !== null && (int) $newParentId !== (int) $previousParentId) {
            $parent = Member::query()->findOrFail((int) $newParentId);

            return $householdMembers->assignToHousehold(
                $record->fresh(),
                $parent,
            );
        }

        if ($newParentId === null && $previousParentId !== null) {
            return $householdMembers->removeFromHousehold($record->fresh());
        }

        return $householdMembers->syncHouseholdAccessFlags($record->fresh());
    }
}
