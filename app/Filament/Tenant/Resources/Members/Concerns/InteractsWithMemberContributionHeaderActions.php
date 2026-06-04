<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\Members\Concerns;

use App\Filament\Support\ContributionTableActions;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Models\Tenant\Member;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Livewire\Component;

trait InteractsWithMemberContributionHeaderActions
{
    protected function buildMemberContributeAction(): Action
    {
        $cycles = app(ContributionCycleService::class);

        return Action::make('contribute')
            ->label(__('Contribute'))
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->visible(fn (): bool => ($member = $this->resolveMemberForContributionAction()) !== null
                && $cycles->contributionCycleSelectOptionsForMember($member) !== [])
            ->schema([
                Select::make('cycle')
                    ->label(__('Cycle'))
                    ->options(fn (): array => ($member = $this->resolveMemberForContributionAction()) !== null
                        ? $cycles->contributionCycleSelectOptionsForMember($member)
                        : [])
                    ->required(),
            ])
            ->fillForm(fn (): array => [
                'cycle' => ($member = $this->resolveMemberForContributionAction()) !== null
                    ? $cycles->defaultContributionCycleKeyForMember($member)
                    : null,
            ])
            ->action(function (array $data) use ($cycles): void {
                $member = $this->resolveMemberForContributionAction();

                if ($member === null) {
                    return;
                }

                [$month, $year] = $cycles->parseContributionCycleKey($data['cycle']);
                $outcome = $cycles->applyContributionForMemberForPeriod($member, $month, $year);

                ContributionTableActions::notifyApplyOutcome($outcome, $member->name);

                if (in_array($outcome, ['applied', 'partial'], true)) {
                    ContributionTableActions::refreshContributionViews($this->resolveContributionRefreshTarget());
                }
            });
    }

    protected function buildMemberAllocateDependentsAction(): Action
    {
        $cycles = app(ContributionCycleService::class);

        return Action::make('allocateDependents')
            ->label(__('Allocate to dependents'))
            ->icon('heroicon-o-users')
            ->color('info')
            ->visible(fn (): bool => ($member = $this->resolveMemberForContributionAction()) instanceof Member
                && $member->dependents()->where('status', 'active')->exists())
            ->schema([
                Select::make('cycle')
                    ->label(__('Cycle'))
                    ->options(fn (): array => ($member = $this->resolveMemberForContributionAction()) instanceof Member
                        ? $cycles->contributionCycleSelectOptionsForBulk()
                        : [])
                    ->required(),
            ])
            ->action(function (array $data) use ($cycles): void {
                $member = $this->resolveMemberForContributionAction();

                if ($member === null) {
                    return;
                }

                [$month, $year] = $cycles->parseContributionCycleKey($data['cycle']);
                $result = $cycles->applyDependentAllocationForParentForPeriod($member, $month, $year);

                foreach ($result['allocated_dependent_ids'] as $dependentId) {
                    $dependent = Member::query()->find($dependentId);

                    if ($dependent !== null) {
                        app(AccountingService::class)->triggerMemberCashCollection($dependent);
                    }
                }

                Notification::make()
                    ->title(__(':count transfer(s) completed', ['count' => $result['transfers']]))
                    ->body(implode("\n", $result['details']))
                    ->success()
                    ->send();

                MemberResource::dispatchMemberDetailInsightsRefresh($this->resolveContributionRefreshTarget());

                if (method_exists($this, 'resetTable')) {
                    $this->resetTable();
                }
            });
    }

    protected function resolveMemberForContributionAction(): ?Member
    {
        if (property_exists($this, 'record') && $this->record instanceof Member) {
            return $this->record;
        }

        if (method_exists($this, 'getOwnerRecord')) {
            $owner = $this->getOwnerRecord();

            return $owner instanceof Member ? $owner : null;
        }

        return null;
    }

    protected function resolveContributionRefreshTarget(): ?Component
    {
        if (method_exists($this, 'getLivewire')) {
            $livewire = $this->getLivewire();

            return $livewire instanceof Component ? $livewire : null;
        }

        return $this instanceof Component ? $this : null;
    }
}
