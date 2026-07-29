<?php

namespace App\Filament\Member\Resources\MyFundPostings\Schemas;

use App\Filament\Support\MemberContributionFilamentActions;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\VoluntaryContributionRequestService;
use App\Support\BusinessDay;
use App\Support\Tenant\CurrentMember;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class MyFundPostingForm
{
    public static function configure(Schema $schema): Schema
    {
        $currency = fn (): string => Setting::get('general', 'currency', 'USD');

        return $schema
            ->components([
                DatePicker::make('posting_date')
                    ->label(__('Date of transfer'))
                    ->required()
                    ->default(BusinessDay::now())
                    ->maxDate(BusinessDay::now()),
                TextInput::make('amount')
                    ->label(__('Amount'))
                    ->numeric()
                    ->required()
                    ->minValue(0.01)
                    ->prefix(fn (): string => $currency()),
                TextInput::make('reference')
                    ->label(__('Reference / receipt number'))
                    ->default(fn (): ?string => CurrentMember::get()?->name)
                    ->maxLength(255)
                    ->placeholder(__('e.g. bank transfer reference')),
                FileUpload::make('attachment')
                    ->label(__('Attachment (e.g. transfer receipt)'))
                    ->disk('public')
                    ->directory('fund-postings')
                    ->acceptedFileTypes(['image/*', 'application/pdf'])
                    ->maxSize(5120),
                Textarea::make('comments')
                    ->label(__('Comments / instructions'))
                    ->rows(3)
                    ->maxLength(1000)
                    ->placeholder(__('Any additional notes for the admin...')),
                ...self::voluntaryTopUpSection(),
            ]);
    }

    /**
     * Optional voluntary top-up section — only rendered when the current member
     * is eligible to request a top-up for the active contribution cycle.
     *
     * @return list<Section>
     */
    private static function voluntaryTopUpSection(): array
    {
        $member = CurrentMember::get();

        if (!MemberContributionFilamentActions::canRequestVoluntaryTopUp()) {
            return [];
        }

        $isParent = $member?->isParent() ?? false;

        return [
            Section::make(__('Contribution top-up'))
                ->description(__('If this deposit includes an extra voluntary contribution for the current cycle, declare it here. Admin will raise your contribution due for this cycle only — your standing monthly allocation stays unchanged.'))
                ->collapsible()
                ->collapsed()
                ->schema([
                    Toggle::make('voluntary_topup_enabled')
                        ->label(__('Include a voluntary top-up with this deposit'))
                        ->live()
                        ->default(false),

                    Select::make('voluntary_topup_target_member_id')
                        ->label(__('For member'))
                        ->options(function () use ($member): array {
                            if ($member === null) {
                                return [];
                            }

                            $options = [(string) $member->id => $member->name . ' (' . __('yourself') . ')'];

                            $member->dependents()
                                ->where('status', 'active')
                                ->orderBy('name')
                                ->get(['id', 'name', 'member_number'])
                                ->each(function (Member $dep) use (&$options): void {
                                    if (MemberContributionFilamentActions::canRequestVoluntaryTopUp($dep)) {
                                        $options[(string) $dep->id] = $dep->member_number . ' — ' . $dep->name;
                                    }
                                });

                            return $options;
                        })
                        ->default(fn(): ?string => $member !== null ? (string) $member->id : null)
                        ->visible($isParent)
                        ->visible(fn(Get $get): bool => (bool) $get('voluntary_topup_enabled') && $isParent)
                        ->required(fn(Get $get): bool => (bool) $get('voluntary_topup_enabled') && $isParent),

                    Select::make('voluntary_topup_amount')
                        ->label(__('Extra amount'))
                        ->options(function (Get $get) use ($member): array {
                            $targetId = (int) ($get('voluntary_topup_target_member_id') ?? $member?->id);
                            $target = $targetId && $targetId !== (int) $member?->id
                                ? Member::query()->find($targetId)
                                : $member;

                            $standing = (float) ($target?->monthly_contribution_amount ?? 0);
                            $options = [];

                            foreach (VoluntaryContributionRequestService::extraAmountOptions() as $extra => $label) {
                                $total = number_format($standing + $extra, 2);
                                $options[(string) ($standing + $extra)] = $label . ' (' . __('total: :total', ['total' => $total]) . ')';
                            }

                            return $options;
                        })
                        ->live()
                        ->visible(fn(Get $get): bool => (bool) $get('voluntary_topup_enabled'))
                        ->required(fn(Get $get): bool => (bool) $get('voluntary_topup_enabled'))
                        ->helperText(__('Multiples of :step, up to +\ :max above the monthly allocation.', [
                            'step' => number_format(VoluntaryContributionRequestService::STEP),
                            'max' => number_format(VoluntaryContributionRequestService::MAX_EXTRA),
                        ])),

                    Textarea::make('voluntary_topup_note')
                        ->label(__('Note (optional)'))
                        ->rows(2)
                        ->maxLength(500)
                        ->visible(fn(Get $get): bool => (bool) $get('voluntary_topup_enabled')),
                ]),
        ];
    }
}
