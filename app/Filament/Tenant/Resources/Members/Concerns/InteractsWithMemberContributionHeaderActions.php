<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\Members\Concerns;

use App\Filament\Support\ContributionTableActions;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Models\Tenant\Member;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;
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
            ->label(__('Allocate'))
            ->icon('heroicon-o-arrow-right-circle')
            ->color('warning')
            ->visible(function () use ($cycles): bool {
                $member = $this->resolveMemberForContributionAction();

                return $member instanceof Member
                    && $cycles->shouldShowDependentAllocationAction($member);
            })
            ->modalHeading(__('Allocate to dependents'))
            ->modalDescription(
                __('Choose the calendar month you are funding dependent cash for (arrears). Preview updates when you change the cycle.')
            )
            ->modalWidth('lg')
            ->schema(function () use ($cycles): array {
                $member = $this->resolveMemberForContributionAction();
                $options = $member instanceof Member
                    ? $cycles->allocationCycleSelectOptionsForParent($member)
                    : [];

                return [
                    Select::make('cycle')
                        ->label(__('Allocation cycle'))
                        ->options($options)
                        ->required()
                        ->live()
                        ->native(false)
                        ->disabled($options === [])
                        ->columnSpanFull(),
                    Placeholder::make('breakdown')
                        ->label('')
                        ->content(function (Get $get) use ($cycles): HtmlString {
                            $member = $this->resolveMemberForContributionAction();

                            if (! $member instanceof Member) {
                                return new HtmlString('');
                            }

                            $key = $get('cycle');

                            if ($key === null || $key === '') {
                                return new HtmlString(
                                    '<p class="text-sm text-gray-500 dark:text-gray-400">'
                                    .e(__('Select a cycle to preview.'))
                                    .'</p>'
                                );
                            }

                            try {
                                [$month, $year] = $cycles->parseContributionCycleKey((string) $key);
                            } catch (InvalidArgumentException) {
                                return new HtmlString('');
                            }

                            return $cycles->dependentAllocationModalDescriptionForPeriod($member, $month, $year);
                        })
                        ->columnSpanFull(),
                ];
            })
            ->fillForm(function () use ($cycles): array {
                $member = $this->resolveMemberForContributionAction();

                return [
                    'cycle' => $member instanceof Member
                        ? ($cycles->defaultAllocationCycleKeyForParent($member) ?? '')
                        : '',
                ];
            })
            ->action(function (array $data, Action $action) use ($cycles): void {
                $member = $this->resolveMemberForContributionAction();

                if ($member === null) {
                    return;
                }

                $key = $data['cycle'] ?? null;

                if (! is_string($key) || $key === '') {
                    Notification::make()
                        ->title(__('Select an allocation cycle'))
                        ->danger()
                        ->send();

                    return;
                }

                try {
                    [$month, $year] = $cycles->parseContributionCycleKey($key);
                } catch (InvalidArgumentException) {
                    Notification::make()
                        ->title(__('Invalid allocation cycle'))
                        ->danger()
                        ->send();

                    return;
                }

                $result = $cycles->applyDependentAllocationForParentForPeriod($member, $month, $year);
                $body = $cycles->formatAllocationResultDetailTableHtml($result['details']);

                foreach ($result['allocated_dependent_ids'] as $dependentId) {
                    $dependent = Member::query()->find($dependentId);

                    if ($dependent !== null) {
                        app(AccountingService::class)->triggerMemberCashCollection($dependent);
                    }
                }

                if ($result['transfers'] > 0) {
                    Notification::make()
                        ->title(__('Allocation completed'))
                        ->body($body)
                        ->success()
                        ->send();
                } else {
                    Notification::make()
                        ->title(__('Allocation'))
                        ->body($body)
                        ->warning()
                        ->send();
                }

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
