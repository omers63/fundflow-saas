<?php

namespace App\Filament\Tenant\Resources\Members\Pages;

use App\Filament\Concerns\RefreshesResourceRecord;
use App\Filament\Tenant\Resources\Members\Concerns\InteractsWithMemberContributionHeaderActions;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Filament\Tenant\Widgets\MemberDetailInsightsWidget;
use App\Models\Tenant\Member;
use App\Services\Tenant\HouseholdMemberService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

class EditMember extends EditRecord
{
    use InteractsWithMemberContributionHeaderActions;
    use RefreshesResourceRecord;

    protected static string $resource = MemberResource::class;

    public function getTitle(): string
    {
        return __('Member');
    }

    public function getHeading(): string
    {
        return $this->record->name;
    }

    public function getSubheading(): ?string
    {
        assert($this->record instanceof Member);

        $status = Member::statusOptions()[$this->record->status] ?? $this->record->status;

        return __(':number · :status', [
            'number' => $this->record->member_number,
            'status' => $status,
        ]);
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

    #[On('refresh-member-detail-insights')]
    public function refreshMemberFromInsights(int $memberId): void
    {
        if ((int) $this->getRecord()->getKey() !== $memberId) {
            return;
        }

        $this->refreshResolvedRecord();
    }

    protected function afterSave(): void
    {
        MemberResource::dispatchMemberDetailInsightsRefresh($this);
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
                filled($data['email'] ?? null) ? (string) $data['email'] : null,
            );
        }

        if ($newParentId === null && $previousParentId !== null) {
            return $householdMembers->removeFromHousehold($record->fresh());
        }

        return $householdMembers->syncHouseholdAccessFlags($record->fresh());
    }
}
