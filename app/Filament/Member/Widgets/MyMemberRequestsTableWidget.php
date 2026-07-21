<?php

declare(strict_types=1);

namespace App\Filament\Member\Widgets;

use App\Filament\Member\Support\MemberRequestFilamentActions;
use App\Filament\Member\Support\ViewMemberRequestAction;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Models\Tenant\MemberRequest;
use App\Support\Tenant\CurrentMember;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

class MyMemberRequestsTableWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    public function getHeading(): ?string
    {
        return __('Membership requests');
    }

    protected function getTableQuery(): Builder|Relation|null
    {
        $member = CurrentMember::get();

        if ($member === null) {
            return MemberRequest::query()->whereRaw('1 = 0');
        }

        return MemberRequest::query()
            ->where('requester_member_id', $member->id)
            ->whereIn('type', [
                MemberRequest::TYPE_FREEZE_MEMBERSHIP,
                MemberRequest::TYPE_UNFREEZE_MEMBERSHIP,
                MemberRequest::TYPE_WITHDRAW_MEMBERSHIP,
                MemberRequest::TYPE_REINSTATE_MEMBERSHIP,
                MemberRequest::TYPE_RELEASE_PAYOUT,
                MemberRequest::TYPE_REQUEST_INDEPENDENCE,
            ]);
    }

    public function table(Table $table): Table
    {
        return TableRecordActionGroups::apply(
            $table
                ->heading(__('Membership requests'))
                ->description(fn (): string => CurrentMember::get()?->parent_member_id !== null
                    ? __('Freeze, leave the fund, or request independence from your household parent. Unfreeze, reinstate, and payout-release requests can also be submitted from the sign-in page when portal access is blocked.')
                    : __('Freeze or leave the fund while you have portal access. Unfreeze, reinstate, and payout-release requests can be submitted from the sign-in page when portal access is blocked.'))
                ->headerActions(MemberRequestFilamentActions::membershipHeaderActions())
                ->filters([
                    SelectFilter::make('status')
                        ->options(MemberRequest::statusOptions()),
                ])
                ->columns([
                    TextColumn::make('type')
                        ->label(__('Request'))
                        ->formatStateUsing(fn (string $state): string => MemberRequest::typeLabel($state)),
                    TextColumn::make('details_display')
                        ->label(__('Details'))
                        ->visibleFrom('md')
                        ->getStateUsing(fn (MemberRequest $record): string => $record->describePayload())
                        ->wrap()
                        ->searchable(false)
                        ->sortable(false),
                    TextColumn::make('status')
                        ->label(__('Status'))
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => MemberRequest::statusOptions()[$state] ?? $state)
                        ->color(fn (string $state): string => match ($state) {
                            MemberRequest::STATUS_PENDING => 'warning',
                            MemberRequest::STATUS_APPROVED => 'success',
                            MemberRequest::STATUS_REJECTED => 'danger',
                            MemberRequest::STATUS_CANCELLED => 'gray',
                            default => 'gray',
                        }),
                    TextColumn::make('created_at')
                        ->label(__('Submitted'))
                        ->dateTime()
                        ->sortable(),
                ])
                ->defaultSort('created_at', 'desc')
                ->emptyStateHeading(__('No membership requests'))
                ->emptyStateDescription(__('Use New request above when you need to change your membership status.'))
                ->toolbarActions([
                    BulkActionGroup::make([
                        TableToolbar::refreshBulkAction(),
                    ]),
                ]),
            [ViewMemberRequestAction::make()],
        );
    }
}
