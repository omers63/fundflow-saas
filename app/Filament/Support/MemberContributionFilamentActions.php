<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Member\Resources\MyContributions\MyContributionResource;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberRequest;
use App\Services\ContributionCycleService;
use App\Services\ContributionService;
use App\Services\Tenant\MemberRequestService;
use App\Services\VoluntaryContributionRequestService;
use App\Support\Tenant\CurrentMember;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

final class MemberContributionFilamentActions
{
    public static function applyOpenPeriodContribution(): Action
    {
        return Action::make('applyOpenPeriodContribution')
            ->label(__('Apply this period'))
            ->icon('heroicon-o-currency-dollar')
            ->color('primary')
            ->visible(function (): bool {
                $member = CurrentMember::get();

                if ($member === null) {
                    return false;
                }

                try {
                    [$month, $year] = app(ContributionCycleService::class)->currentOpenPeriod();
                } catch (\Throwable) {
                    return false;
                }

                if ($member->isExemptFromContributions($month, $year)) {
                    return false;
                }

                if ((float) $member->monthly_contribution_amount <= 0) {
                    return false;
                }

                if (Contribution::periodFullyPosted($member->id, $month, $year)) {
                    return false;
                }

                return true;
            })
            ->requiresConfirmation()
            ->modalHeading(__('Apply contribution for this period from cash'))
            ->modalDescription(__('Create and post your monthly contribution for the open cycle using your cash account.'))
            ->action(function (Component $livewire): void {
                $member = CurrentMember::get();

                if ($member === null) {
                    return;
                }

                try {
                    [$month, $year] = app(ContributionCycleService::class)->currentOpenPeriod();
                } catch (\Throwable) {
                    return;
                }

                $results = [
                    'applied' => [],
                    'insufficient' => [],
                    'skipped' => [],
                ];

                $outcome = app(ContributionService::class)->applyForPeriod($member, $month, $year, $results);

                $notification = Notification::make()
                    ->title(match ($outcome) {
                        'applied', 'partial' => __('Contribution applied'),
                        'insufficient' => __('Insufficient cash balance'),
                        'already_contributed' => __('Already contributed for this period'),
                        'exempt' => __('Contributions are paused'),
                        default => __('Nothing to apply'),
                    });

                $body = match ($outcome) {
                    'partial' => __('We applied as much as possible from your cash balance.'),
                    'insufficient' => __('Your cash balance is too low to apply this contribution.'),
                    'already_contributed' => __('This period already has a posted contribution.'),
                    'exempt' => __('You are exempt from contributions for this period.'),
                    default => null,
                };

                if ($body !== null) {
                    $notification->body($body);
                }

                match ($outcome) {
                    'applied', 'partial' => $notification->success(),
                    'insufficient' => $notification->warning(),
                    default => $notification->info(),
                };

                $notification->send();

                self::refreshContributionViews($livewire);
            });
    }

    public static function requestOpenCycleAmount(?Member $forDependent = null): Action
    {
        $cycles = app(ContributionCycleService::class);
        $actionName = $forDependent !== null
            ? 'requestOpenCycleAmountForDependent'
            : 'requestOpenCycleAmount';

        return Action::make($actionName)
            ->label(__('Request larger cycle amount'))
            ->icon('heroicon-o-arrow-trending-up')
            ->color('warning')
            ->visible(fn (): bool => self::canRequestOpenCycleAmount($forDependent))
            ->modalHeading(__('Request larger amount for this cycle'))
            ->modalDescription(function () use ($forDependent, $cycles): string {
                [$month, $year] = $cycles->currentOpenPeriod();
                $period = $cycles->periodLabel($month, $year);
                $member = CurrentMember::get();
                $target = $forDependent ?? $member;
                $standing = number_format((float) ($target?->monthly_contribution_amount ?? 0), 2);

                return __('Ask administrators to replace this cycle’s contribution due with a larger amount for :period. Your standing monthly allocation (:amount) stays unchanged for future cycles.', [
                    'period' => $period,
                    'amount' => $standing,
                ]);
            })
            ->modalSubmitActionLabel(__('Submit request'))
            ->schema(function () use ($forDependent): array {
                $member = CurrentMember::get();
                $target = $forDependent ?? $member;
                $min = (float) ($target?->monthly_contribution_amount ?? 0) + 0.01;

                return [
                    TextInput::make('amount')
                        ->label(__('Requested amount'))
                        ->numeric()
                        ->required()
                        ->minValue($min)
                        ->helperText(__('Must be greater than the member’s current monthly allocation.')),
                    Textarea::make('note')
                        ->label(__('Note (optional)'))
                        ->rows(3)
                        ->maxLength(500),
                ];
            })
            ->action(function (array $data) use ($forDependent): void {
                $member = CurrentMember::get();

                if ($member === null) {
                    return;
                }

                try {
                    app(MemberRequestService::class)->submit($member, MemberRequest::TYPE_OPEN_CYCLE_CONTRIBUTION, [
                        'amount' => $data['amount'],
                        'note' => $data['note'] ?? null,
                        'target_member_id' => ($forDependent ?? $member)->id,
                    ]);

                    Notification::make()
                        ->title(__('Request submitted'))
                        ->body(__('Administrators will review your open-cycle contribution amount request.'))
                        ->success()
                        ->send();
                } catch (ValidationException $exception) {
                    $message = collect($exception->errors())->flatten()->first() ?? $exception->getMessage();

                    Notification::make()
                        ->title(__('Could not submit'))
                        ->body($message)
                        ->danger()
                        ->send();
                }
            });
    }

    public static function canRequestOpenCycleAmount(?Member $forDependent = null): bool
    {
        $member = CurrentMember::get();

        if ($member === null || $member->status !== 'active') {
            return false;
        }

        $cycles = app(ContributionCycleService::class);

        try {
            [$month, $year] = $cycles->currentOpenPeriod();
        } catch (\Throwable) {
            return false;
        }

        $target = $forDependent ?? $member;

        if ($target->status !== 'active') {
            return false;
        }

        if ($forDependent !== null && (int) $target->parent_member_id !== (int) $member->id) {
            return false;
        }

        if (! $cycles->memberIsLiableForContributionPeriod($target, $month, $year)) {
            return false;
        }

        return ! Contribution::periodFullyPosted($target->id, $month, $year);
    }

    public static function voluntaryTopUp(?Member $forDependent = null): Action
    {
        $cycles = app(ContributionCycleService::class);
        $actionName = $forDependent !== null
            ? 'voluntaryTopUpForDependent'
            : 'voluntaryTopUp';

        return Action::make($actionName)
            ->label(__('Contribution top-up'))
            ->icon('heroicon-o-plus-circle')
            ->color('success')
            ->visible(fn (): bool => $forDependent !== null
                ? self::canRequestVoluntaryTopUp($forDependent)
                : self::canRequestVoluntaryTopUpForHousehold())
            ->modalHeading(__('Contribution top-up'))
            ->modalDescription(function () use ($forDependent, $cycles): string {
                [$month, $year] = $cycles->currentOpenPeriod();
                $period = $cycles->periodLabel($month, $year);

                if ($forDependent !== null) {
                    return __('Add a top-up to the :period cycle for :name on top of their standing monthly allocation (:standing). The combined total will be collected on the normal collection day. Standing monthly allocation stays unchanged for future cycles.', [
                        'period' => $period,
                        'name' => $forDependent->name,
                        'standing' => number_format((float) $forDependent->monthly_contribution_amount, 2),
                    ]);
                }

                return __('Add a top-up to the :period cycle for yourself and/or any eligible dependents. Select each member and choose their top-up amount — amounts can differ. Combined totals are collected on the normal collection day. Standing monthly allocations stay unchanged for future cycles.', [
                    'period' => $period,
                ]);
            })
            ->modalSubmitActionLabel(__('Submit request'))
            ->schema(function () use ($forDependent): array {
                $member = CurrentMember::get();
                $targets = $forDependent !== null
                    ? [$forDependent]
                    : self::eligibleVoluntaryTopUpTargets($member);

                $fields = [];

                if (count($targets) > 1) {
                    foreach ($targets as $target) {
                        $id = (string) $target->id;
                        $standing = (float) $target->monthly_contribution_amount;
                        $label = (int) $target->id === (int) $member?->id
                            ? $target->name.' ('.__('yourself').')'
                            : $target->member_number.' — '.$target->name;

                        $extraOptions = [];
                        foreach (VoluntaryContributionRequestService::extraAmountOptions() as $extra => $extraLabel) {
                            $total = number_format($standing + $extra, 2);
                            $extraOptions[(string) $extra] = $extraLabel.' ('.__('total: :total', ['total' => $total]).')';
                        }

                        $fields[] = Checkbox::make("topups.{$id}.include")
                            ->label($label)
                            ->helperText(__('Standing: :amount', ['amount' => number_format($standing, 2)]))
                            ->live()
                            ->default(false);

                        $fields[] = Select::make("topups.{$id}.extra")
                            ->label(__('Top-up amount'))
                            ->options($extraOptions)
                            ->visible(fn (Get $get): bool => (bool) $get("topups.{$id}.include"))
                            ->required(fn (Get $get): bool => (bool) $get("topups.{$id}.include"))
                            ->dehydrated(fn (Get $get): bool => (bool) $get("topups.{$id}.include"));
                    }
                } else {
                    $standing = (float) ($targets[0]->monthly_contribution_amount ?? 0);
                    $extraOptions = [];

                    foreach (VoluntaryContributionRequestService::extraAmountOptions() as $extra => $extraLabel) {
                        $total = number_format($standing + $extra, 2);
                        $extraOptions[(string) $extra] = $extraLabel.' ('.__('total: :total', ['total' => $total]).')';
                    }

                    $fields[] = Select::make('extra')
                        ->label(__('Top-up amount'))
                        ->options($extraOptions)
                        ->required()
                        ->helperText(__('Amount must be a multiple of :step (max +\ :max above your monthly allocation).', [
                            'step' => number_format(VoluntaryContributionRequestService::STEP),
                            'max' => number_format(VoluntaryContributionRequestService::MAX_EXTRA),
                        ]));
                }

                $fields[] = Textarea::make('note')
                    ->label(__('Note (optional)'))
                    ->rows(3)
                    ->maxLength(500);

                return $fields;
            })
            ->action(function (array $data) use ($forDependent): void {
                $member = CurrentMember::get();

                if ($member === null) {
                    return;
                }

                $eligibleById = collect(
                    $forDependent !== null
                    ? (self::canRequestVoluntaryTopUp($forDependent, $member) ? [$forDependent] : [])
                    : self::eligibleVoluntaryTopUpTargets($member)
                )->keyBy(fn (Member $candidate): int => (int) $candidate->id);

                /** @var list<array{target: Member, extra: float}> $selections */
                $selections = [];

                if ($forDependent !== null || $eligibleById->count() === 1) {
                    $target = $forDependent ?? $eligibleById->first();
                    $extra = (float) ($data['extra'] ?? 0);

                    if ($target instanceof Member && $extra > 0) {
                        $selections[] = ['target' => $target, 'extra' => $extra];
                    }
                } else {
                    foreach ($data['topups'] ?? [] as $targetId => $row) {
                        if (! is_array($row) || empty($row['include']) || empty($row['extra'])) {
                            continue;
                        }

                        $target = $eligibleById->get((int) $targetId);

                        if ($target instanceof Member) {
                            $selections[] = ['target' => $target, 'extra' => (float) $row['extra']];
                        }
                    }
                }

                if ($selections === []) {
                    Notification::make()
                        ->title(__('Could not submit'))
                        ->body(__('Select at least one eligible member and a top-up amount.'))
                        ->danger()
                        ->send();

                    return;
                }

                $submitted = 0;
                $lastError = null;

                foreach ($selections as $selection) {
                    $target = $selection['target'];
                    $extra = $selection['extra'];

                    try {
                        app(MemberRequestService::class)->submit($member, MemberRequest::TYPE_VOLUNTARY_CONTRIBUTION, [
                            'amount' => round((float) $target->monthly_contribution_amount + $extra, 2),
                            'note' => $data['note'] ?? null,
                            'target_member_id' => $target->id,
                        ]);
                        $submitted++;
                    } catch (ValidationException $exception) {
                        $lastError = collect($exception->errors())->flatten()->first() ?? $exception->getMessage();
                    }
                }

                if ($submitted > 0) {
                    Notification::make()
                        ->title(__('Request submitted'))
                        ->body(__('Administrators will review your top-up request. The extra amount will be collected with your regular contribution once approved.'))
                        ->success()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('Could not submit'))
                    ->body($lastError ?? __('Could not submit'))
                    ->danger()
                    ->send();
            });
    }

    public static function canRequestVoluntaryTopUp(?Member $forDependent = null, ?Member $requester = null): bool
    {
        $member = $requester ?? CurrentMember::get();

        if ($member === null || $member->status !== 'active') {
            return false;
        }

        $cycles = app(ContributionCycleService::class);

        try {
            [$month, $year] = $cycles->currentOpenPeriod();
        } catch (\Throwable) {
            return false;
        }

        $target = $forDependent ?? $member;

        if ($target->status !== 'active') {
            return false;
        }

        if ($forDependent !== null && (int) $target->parent_member_id !== (int) $member->id) {
            return false;
        }

        if ($target->monthly_contribution_amount <= 0) {
            return false;
        }

        if (! $cycles->memberIsLiableForContributionPeriod($target, $month, $year)) {
            return false;
        }

        if (Contribution::periodFullyPosted($target->id, $month, $year)) {
            return false;
        }

        return ! MemberRequest::query()
            ->where('status', MemberRequest::STATUS_PENDING)
            ->where('type', MemberRequest::TYPE_VOLUNTARY_CONTRIBUTION)
            ->get()
            ->contains(function (MemberRequest $request) use ($target, $month, $year): bool {
                $p = $request->payload ?? [];
                $pid = (int) ($p['target_member_id'] ?? $request->requester_member_id);

                return $pid === (int) $target->id
                    && (int) ($p['period_month'] ?? 0) === $month
                    && (int) ($p['period_year'] ?? 0) === $year;
            });
    }

    /**
     * True when the member may top up themselves or any eligible dependent.
     */
    public static function canRequestVoluntaryTopUpForHousehold(?Member $requester = null): bool
    {
        return self::eligibleVoluntaryTopUpTargets($requester) !== [];
    }

    /**
     * Members the requester may submit a voluntary top-up for (self and/or dependents).
     *
     * @return list<Member>
     */
    public static function eligibleVoluntaryTopUpTargets(?Member $requester = null): array
    {
        $member = $requester ?? CurrentMember::get();

        if ($member === null || $member->status !== 'active') {
            return [];
        }

        $targets = [];

        if (self::canRequestVoluntaryTopUp(null, $member)) {
            $targets[] = $member;
        }

        if ($member->isParent()) {
            $member->dependents()
                ->where('status', 'active')
                ->orderBy('name')
                ->get()
                ->each(function (Member $dependent) use ($member, &$targets): void {
                    if (self::canRequestVoluntaryTopUp($dependent, $member)) {
                        $targets[] = $dependent;
                    }
                });
        }

        return $targets;
    }

    /**
     * Row action for a household dependent (parent requesting on their behalf).
     */
    public static function requestOpenCycleAmountForDependentRow(): Action
    {
        $cycles = app(ContributionCycleService::class);

        return Action::make('requestOpenCycleAmountForDependent')
            ->label(__('Request Large Top-Up'))
            ->icon('heroicon-o-arrow-trending-up')
            ->color('warning')
            ->visible(fn (Member $record): bool => self::canRequestOpenCycleAmount($record))
            ->modalHeading(__('Request Large Top-Up'))
            ->modalDescription(function (Member $record) use ($cycles): string {
                [$month, $year] = $cycles->currentOpenPeriod();

                return __('Ask administrators to replace this cycle’s contribution due with a larger amount for :period. Standing monthly allocation (:amount) for :name stays unchanged for future cycles.', [
                    'period' => $cycles->periodLabel($month, $year),
                    'amount' => number_format((float) $record->monthly_contribution_amount, 2),
                    'name' => $record->name,
                ]);
            })
            ->modalSubmitActionLabel(__('Submit request'))
            ->schema(fn (Member $record): array => [
                TextInput::make('amount')
                    ->label(__('Requested amount'))
                    ->numeric()
                    ->required()
                    ->minValue((float) $record->monthly_contribution_amount + 0.01)
                    ->helperText(__('Must be greater than the member’s current monthly allocation.')),
                Textarea::make('note')
                    ->label(__('Note (optional)'))
                    ->rows(3)
                    ->maxLength(500),
            ])
            ->action(function (Member $record, array $data): void {
                $parent = CurrentMember::get();

                if ($parent === null) {
                    return;
                }

                try {
                    app(MemberRequestService::class)->submit($parent, MemberRequest::TYPE_OPEN_CYCLE_CONTRIBUTION, [
                        'amount' => $data['amount'],
                        'note' => $data['note'] ?? null,
                        'target_member_id' => $record->id,
                    ]);

                    Notification::make()
                        ->title(__('Request submitted'))
                        ->body(__('Administrators will review your open-cycle contribution amount request.'))
                        ->success()
                        ->send();
                } catch (ValidationException $exception) {
                    $message = collect($exception->errors())->flatten()->first() ?? $exception->getMessage();

                    Notification::make()
                        ->title(__('Could not submit'))
                        ->body($message)
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * Row action on the dependents table — parent submits a top-up for a dependent.
     */
    public static function voluntaryTopUpForDependentRow(): Action
    {
        $cycles = app(ContributionCycleService::class);

        return Action::make('voluntaryTopUpForDependentRow')
            ->label(__('Add Standard Top-Up'))
            ->icon('heroicon-o-plus-circle')
            ->color('success')
            ->visible(fn (Member $record): bool => self::canRequestVoluntaryTopUp($record))
            ->modalHeading(__('Add Standard Top-Up'))
            ->modalDescription(function (Member $record) use ($cycles): string {
                [$month, $year] = $cycles->currentOpenPeriod();

                return __('Add a top-up to the :period cycle for :name on top of their standing monthly allocation (:standing). The combined total will be collected on the normal collection day. Standing monthly allocation stays unchanged for future cycles.', [
                    'period' => $cycles->periodLabel($month, $year),
                    'name' => $record->name,
                    'standing' => number_format((float) $record->monthly_contribution_amount, 2),
                ]);
            })
            ->modalSubmitActionLabel(__('Submit request'))
            ->schema(fn (Member $record): array => [
                Select::make('amount')
                    ->label(__('Top-up amount'))
                    ->options(function () use ($record): array {
                        $standing = (float) $record->monthly_contribution_amount;
                        $options = [];

                        foreach (VoluntaryContributionRequestService::extraAmountOptions() as $extra => $label) {
                            $total = number_format($standing + $extra, 2);
                            $options[$standing + $extra] = $label.' ('.__('total: :total', ['total' => $total]).')';
                        }

                        return $options;
                    })
                    ->required()
                    ->helperText(__('Multiples of :step, up to +\ :max above the monthly allocation.', [
                        'step' => number_format(VoluntaryContributionRequestService::STEP),
                        'max' => number_format(VoluntaryContributionRequestService::MAX_EXTRA),
                    ])),
                Textarea::make('note')
                    ->label(__('Note (optional)'))
                    ->rows(3)
                    ->maxLength(500),
            ])
            ->action(function (Member $record, array $data): void {
                $parent = CurrentMember::get();

                if ($parent === null) {
                    return;
                }

                try {
                    app(MemberRequestService::class)->submit($parent, MemberRequest::TYPE_VOLUNTARY_CONTRIBUTION, [
                        'amount' => $data['amount'],
                        'note' => $data['note'] ?? null,
                        'target_member_id' => $record->id,
                    ]);

                    Notification::make()
                        ->title(__('Request submitted'))
                        ->body(__('Administrators will review the top-up request for :name.', ['name' => $record->name]))
                        ->success()
                        ->send();
                } catch (ValidationException $exception) {
                    $message = collect($exception->errors())->flatten()->first() ?? $exception->getMessage();

                    Notification::make()
                        ->title(__('Could not submit'))
                        ->body($message)
                        ->danger()
                        ->send();
                }
            });
    }

    public static function refreshContributionViews(Component $livewire): void
    {
        MyContributionResource::dispatchInsightsRefresh($livewire);

        if (method_exists($livewire, 'resetTable')) {
            $livewire->resetTable();
        }
    }
}
