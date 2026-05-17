<?php

namespace App\Filament\Tenant\Resources\Members\Pages;

use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Models\Tenant\Member;
use App\Services\Tenant\HouseholdMemberService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditMember extends EditRecord
{
    protected static string $resource = MemberResource::class;

    public function getTitle(): string
    {
        return __('Member');
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
