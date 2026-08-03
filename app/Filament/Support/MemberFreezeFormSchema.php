<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Tenant\Member;
use App\Services\ContributionCycleService;
use App\Services\MemberFreezeService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * Shared freeze-membership form building blocks (member wizard + admin sections).
 */
final class MemberFreezeFormSchema
{
    /**
     * Member portal: multi-step wizard inside the freeze request modal.
     *
     * @param  list<string>  $blockers
     * @return list<Wizard>
     */
    public static function requestWizard(Member $member, array $blockers): array
    {
        $dependentOptions = self::dependentOptions($member);

        $steps = [
            Step::make(__('Duration'))
                ->description(__('Expected freeze length'))
                ->icon(Heroicon::OutlinedCalendarDays)
                ->schema(self::durationFields()),
        ];

        if ($member->isParent()) {
            $steps[] = Step::make(__('Household'))
                ->description(__('Dependents'))
                ->icon(Heroicon::OutlinedUserGroup)
                ->schema(self::householdFields($dependentOptions));
        }

        $steps[] = Step::make(__('Checklist'))
            ->description(__('Before submitting'))
            ->icon(Heroicon::OutlinedClipboardDocumentCheck)
            ->schema(self::checklistFields($member, $blockers));

        return [
            Wizard::make($steps)
                ->skippable(false)
                ->persistStepInQueryString(null),
        ];
    }

    /**
     * Admin portal: sectioned single form (no wizard).
     *
     * @param  list<string>  $blockers
     * @return list<Section>
     */
    public static function requestSections(
        Member $member,
        array $blockers,
        bool $includeFreezeDate = false,
    ): array {
        $dependentOptions = self::dependentOptions($member);

        $sections = [
            Section::make(__('Duration'))
                ->description(__('How long this freeze plan should last.'))
                ->icon('heroicon-o-calendar-days')
                ->schema([
                    ...self::durationFields(),
                    ...($includeFreezeDate ? [MemberFilamentActions::freezeDateField()] : []),
                ]),
        ];

        if ($member->isParent()) {
            $sections[] = Section::make(__('Household'))
                ->description(__('How dependents should be handled while frozen.'))
                ->icon('heroicon-o-user-group')
                ->schema(self::householdFields($dependentOptions));
        }

        $sections[] = Section::make(__('Before you continue'))
            ->description(__('Review readiness and what happens after approval.'))
            ->icon('heroicon-o-clipboard-document-check')
            ->schema(self::checklistFields($member, $blockers));

        return $sections;
    }

    /**
     * @return list<Component>
     */
    private static function durationFields(): array
    {
        return [
            TextInput::make('cycles')
                ->label(__('Expected freeze cycles'))
                ->numeric()
                ->minValue(MemberFreezeService::INDEFINITE_CYCLES)
                ->maxValue(MemberFreezeService::MAX_CYCLES)
                ->default(1)
                ->live(debounce: 300)
                ->helperText(__('Leave blank or enter 0 for indefinite. Otherwise 1–:max contribution cycles. A plan only — membership stays frozen until unfrozen. After a finite plan ends, late fees and delinquency resume.', [
                    'max' => MemberFreezeService::MAX_CYCLES,
                ])),
            Placeholder::make('cycle_preview')
                ->hiddenLabel()
                ->content(fn($get): HtmlString => self::cyclePreviewHtml(
                    MemberFreezeService::normalizeCycles($get('cycles')),
                )),
            Textarea::make('reason')
                ->label(__('Reason (optional)'))
                ->rows(3)
                ->maxLength(500)
                ->columnSpanFull(),
        ];
    }

    /**
     * @param  array<int|string, string>  $dependentOptions
     * @return list<Component>
     */
    private static function householdFields(array $dependentOptions): array
    {
        return [
            Radio::make('household_mode')
                ->label(__('Household during freeze'))
                ->options([
                    MemberFreezeService::HOUSEHOLD_SELF_ONLY => __('Freeze me only'),
                    MemberFreezeService::HOUSEHOLD_INCLUDE_DEPENDENTS => __('Freeze me and all dependents'),
                    MemberFreezeService::HOUSEHOLD_TEMP_PARENT => __('Elect a temporary funding parent'),
                ])
                ->descriptions([
                    MemberFreezeService::HOUSEHOLD_SELF_ONLY => __('Dependents stay active; your freeze does not cascade.'),
                    MemberFreezeService::HOUSEHOLD_INCLUDE_DEPENDENTS => __('Each dependent receives a full freeze for the same plan length.'),
                    MemberFreezeService::HOUSEHOLD_TEMP_PARENT => __('One login-capable dependent funds the household. Your parent link stays unchanged.'),
                ])
                ->default(MemberFreezeService::HOUSEHOLD_SELF_ONLY)
                ->required()
                ->live(),
            Select::make('temporary_parent_member_id')
                ->label(__('Temporary funding parent'))
                ->options($dependentOptions)
                ->searchable()
                ->native(false)
                ->visible(fn ($get): bool => $get('household_mode') === MemberFreezeService::HOUSEHOLD_TEMP_PARENT)
                ->required(fn ($get): bool => $get('household_mode') === MemberFreezeService::HOUSEHOLD_TEMP_PARENT)
                ->helperText(__('Must be able to log in and have cash capacity. They fund all dependents (including themselves) while you are frozen.')),
        ];
    }

    /**
     * @param  list<string>  $blockers
     * @return list<\Filament\Schemas\Components\Component|Component>
     */
    private static function checklistFields(Member $member, array $blockers): array
    {
        return [
            Placeholder::make('blockers')
                ->hiddenLabel()
                ->content(new HtmlString(self::blockersHtml($blockers))),
            Actions::make([
                self::notifyBorrowersAction($member),
            ]),
            Placeholder::make('impact')
                ->hiddenLabel()
                ->content(new HtmlString(self::impactHtml())),
        ];
    }

    private static function notifyBorrowersAction(Member $member): Action
    {
        return Action::make('notifyBorrowersToReplaceGuarantor')
            ->label(__('Notify borrowers to replace guarantor'))
            ->icon('heroicon-o-bell-alert')
            ->color('warning')
            ->visible(fn (): bool => app(MemberFreezeService::class)->unresolvedGuarantorLoans($member) !== [])
            ->requiresConfirmation()
            ->modalHeading(__('Notify borrowers?'))
            ->modalDescription(__('Sends a message to each borrower who still lists you as guarantor, asking them to propose a replacement. This does not submit your freeze request.'))
            ->action(function () use ($member): void {
                try {
                    $result = app(MemberFreezeService::class)->notifyBorrowersToReplaceGuarantor($member);

                    Notification::make()
                        ->title(__('Borrowers notified'))
                        ->body(__('Notified :count borrower(s) to replace you as guarantor.', [
                            'count' => $result['notified'],
                        ]))
                        ->success()
                        ->send();
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title(__('Could not notify'))
                        ->body(collect($exception->errors())->flatten()->first())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * @return array<int|string, string>
     */
    private static function dependentOptions(Member $member): array
    {
        if (! $member->isParent()) {
            return [];
        }

        return $member->dependents()
            ->where('status', 'active')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @param  list<string>  $blockers
     */
    public static function blockersHtml(array $blockers): string
    {
        if ($blockers === []) {
            return view('filament.partials.freeze-callout', [
                'tone' => 'success',
                'title' => __('Ready to submit'),
                'body' => e(__('No blocking pending items or unresolved guarantor roles.')),
            ])->render();
        }

        return view('filament.partials.freeze-callout', [
            'tone' => 'danger',
            'title' => __('Resolve these before freeze can proceed'),
            'items' => array_map(static fn (string $reason): string => e($reason), $blockers),
        ])->render();
    }

    public static function impactHtml(): string
    {
        return view('filament.partials.freeze-callout', [
            'tone' => 'info',
            'title' => __('What happens after approval'),
            'items' => [
                e(__('Contributions exempted; open pending contribution rows cancelled.')),
                e(__('Cash-out remains unavailable while frozen.')),
                e(__('Active loan EMIs shift forward one cycle per planned freeze cycle; overdue late flags waived on shift.')),
                e(__('Guarantor liability on your loans is paused during the planned freeze.')),
                e(__('Portal becomes read-only with freeze status and unfreeze/extend actions.')),
            ],
        ])->render();
    }

    public static function cyclePreviewHtml(int $cycles): HtmlString
    {
        if (MemberFreezeService::isIndefiniteCycles($cycles)) {
            return new HtmlString(view('filament.partials.freeze-callout', [
                'tone' => 'info',
                'title' => __('Estimated window'),
                'body' => e(__('Indefinite freeze — fees and EMI shifts continue until unfreeze.')),
            ])->render());
        }

        if ($cycles < MemberFreezeService::MIN_CYCLES) {
            return new HtmlString(view('filament.partials.freeze-callout', [
                'tone' => 'info',
                'title' => __('Estimated window'),
                'body' => e(__('Enter a cycle count to preview the plan window, or leave blank / 0 for indefinite.')),
            ])->render());
        }

        $svc = app(ContributionCycleService::class);
        [$m, $y] = $svc->currentOpenPeriod();
        $start = $svc->periodLabel($m, $y);
        $cursor = Carbon::create($y, $m, 1)->addMonthsNoOverflow($cycles - 1);
        $end = $svc->periodLabel((int) $cursor->month, (int) $cursor->year);

        return new HtmlString(view('filament.partials.freeze-callout', [
            'tone' => 'info',
            'title' => __('Estimated window'),
            'body' => e(__(':start → :end (:cycles cycles)', [
                'start' => $start,
                'end' => $end,
                'cycles' => $cycles,
            ])),
        ])->render());
    }
}
