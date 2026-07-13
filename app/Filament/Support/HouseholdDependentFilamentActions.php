<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Models\Tenant\Member;
use App\Services\Tenant\HouseholdMemberService;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Livewire\Component;

final class HouseholdDependentFilamentActions
{
    /**
     * @return list<Action>
     */
    public static function headerActions(Closure $resolveParent): array
    {
        return [
            self::addDependent($resolveParent),
        ];
    }

    public static function addDependent(Closure $resolveParent): Action
    {
        return Action::make('addDependent')
            ->label(__('Add dependent'))
            ->icon('heroicon-o-user-plus')
            ->color('success')
            ->visible(function () use ($resolveParent): bool {
                $parent = $resolveParent();

                return $parent instanceof Member
                    && $parent->isParent()
                    && self::isTenantAdmin();
            })
            ->modalHeading(__('Add household dependent'))
            ->modalDescription(__('Link an existing independent member under this household head. The member must already have a portal login account.'))
            ->modalWidth('md')
            ->schema(function () use ($resolveParent): array {
                $parent = $resolveParent();
                $options = $parent instanceof Member
                    ? self::assignableMemberOptions($parent)
                    : [];

                return [
                    Select::make('member_id')
                        ->label(__('Member'))
                        ->options($options)
                        ->searchable()
                        ->required()
                        ->disabled($options === [])
                        ->helperText($options === []
                            ? __('No eligible independent members are available to link.')
                            : __('Only independent members with a portal login can be added.')),
                    TextInput::make('contact_email')
                        ->label(__('Contact email'))
                        ->email()
                        ->maxLength(255)
                        ->helperText(__('Optional. Must match the parent household email when provided; otherwise the member is linked under the household email.')),
                ];
            })
            ->action(function (array $data, Action $action, Component $livewire) use ($resolveParent): void {
                $parent = $resolveParent();

                if (! $parent instanceof Member) {
                    return;
                }

                $member = Member::query()->find((int) ($data['member_id'] ?? 0));

                if (! $member instanceof Member) {
                    ActionModalFailure::present($action, __('Selected member could not be found.'), __('Cannot add dependent'));

                    return;
                }

                $contactEmail = filled($data['contact_email'] ?? null)
                    ? (string) $data['contact_email']
                    : null;

                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        fn () => app(HouseholdMemberService::class)->assignToHousehold($member, $parent, $contactEmail),
                        __('Cannot add dependent'),
                    )
                ) {
                    return;
                }

                Notification::make()
                    ->title(__('Dependent added'))
                    ->body(__(':name is now linked to :parent.', [
                        'name' => $member->fresh()->name,
                        'parent' => $parent->name,
                    ]))
                    ->success()
                    ->send();

                self::refreshHouseholdViews($livewire);
            });
    }

    /**
     * @return array<int, string>
     */
    public static function assignableMemberOptions(Member $parent): array
    {
        return Member::query()
            ->whereNull('parent_member_id')
            ->whereKeyNot($parent->id)
            ->whereNotNull('user_id')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Member $member): array => [
                $member->id => $member->member_number.' — '.$member->name,
            ])
            ->all();
    }

    private static function isTenantAdmin(): bool
    {
        return (bool) auth('tenant')->user()?->is_admin;
    }

    private static function refreshHouseholdViews(Component $livewire): void
    {
        MemberResource::dispatchMemberDetailInsightsRefresh($livewire);

        if (method_exists($livewire, 'resetTable')) {
            $livewire->resetTable();
        }
    }

    /**
     * @return list<Action>
     */
    public static function forRow(Closure $resolveParent): array
    {
        return [
            ViewAction::make()
                ->url(fn (Member $record): string => MemberResource::getUrl('view', ['record' => $record])),
            ...DependentAllocationFilamentActions::forRow($resolveParent),
            ...MemberFilamentActions::forHouseholdDependentMemberRow(),
            ...MemberDelinquencyActions::forMemberListRow(),
        ];
    }

    /**
     * @return list<BulkAction>
     */
    public static function forBulk(Closure $resolveParent): array
    {
        return [
            ...MemberFilamentActions::forMemberListBulk(),
            ...MemberDelinquencyActions::forMemberListBulk(),
        ];
    }
}
