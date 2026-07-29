<?php

namespace App\Filament\Member\Resources\MyFundPostings\Schemas;

use App\Filament\Support\MemberContributionFilamentActions;
use App\Models\Tenant\Setting;
use App\Services\VoluntaryContributionRequestService;
use App\Support\BusinessDay;
use App\Support\Tenant\CurrentMember;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
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
                ...self::contributionTopUpSection(),
            ]);
    }

    /**
     * Optional contribution top-up section — shown when the member may top up
     * themselves and/or any eligible dependent for the open cycle.
     *
     * @return list<Section>
     */
    private static function contributionTopUpSection(): array
    {
        $member = CurrentMember::get();
        $targets = MemberContributionFilamentActions::eligibleVoluntaryTopUpTargets($member);

        if ($targets === []) {
            return [];
        }

        $memberRows = [];

        foreach ($targets as $target) {
            $id = (string) $target->id;
            $standing = (float) $target->monthly_contribution_amount;
            $isSelf = (int) $target->id === (int) $member?->id;
            $label = $isSelf
                ? $target->name.' ('.__('yourself').')'
                : $target->member_number.' — '.$target->name;

            $extraOptions = [];
            foreach (VoluntaryContributionRequestService::extraAmountOptions() as $extra => $extraLabel) {
                $total = number_format($standing + $extra, 2);
                $extraOptions[(string) $extra] = $extraLabel.' ('.__('total: :total', ['total' => $total]).')';
            }

            $memberRows[] = Grid::make([
                'default' => 1,
                'md' => 12,
            ])
                ->schema([
                    Checkbox::make("contribution_topups.{$id}.include")
                        ->label($label)
                        ->helperText(__('Standing: :amount', ['amount' => number_format($standing, 2)]))
                        ->live()
                        ->default(false)
                        ->columnSpan(['md' => 6]),
                    Select::make("contribution_topups.{$id}.extra")
                        ->label(__('Top-up amount'))
                        ->options($extraOptions)
                        ->placeholder(__('Choose amount'))
                        ->visible(fn (Get $get): bool => (bool) $get("contribution_topups.{$id}.include"))
                        ->required(fn (Get $get): bool => (bool) $get("contribution_topups.{$id}.include"))
                        ->dehydrated(fn (Get $get): bool => (bool) $get("contribution_topups.{$id}.include"))
                        ->helperText(__('Multiples of :step, up to +\ :max above standing.', [
                            'step' => number_format(VoluntaryContributionRequestService::STEP),
                            'max' => number_format(VoluntaryContributionRequestService::MAX_EXTRA),
                        ]))
                        ->columnSpan(['md' => 6]),
                ]);
        }

        return [
            Section::make(__('Contribution top-up'))
                ->description(__('If this deposit includes an extra contribution for the current cycle, declare it here. Select each member and choose their top-up amount. Admin will raise the contribution due for this cycle only — standing monthly allocation stays unchanged.'))
                ->collapsible()
                ->collapsed()
                ->schema([
                    Toggle::make('contribution_topup_enabled')
                        ->label(__('Include a contribution top-up with this deposit'))
                        ->live()
                        ->default(false),

                    Section::make(__('Members'))
                        ->description(__('Tick a member, then set a top-up amount for that member. Amounts can differ.'))
                        ->schema($memberRows)
                        ->visible(fn (Get $get): bool => (bool) $get('contribution_topup_enabled'))
                        ->compact()
                        ->contained(false),

                    Textarea::make('contribution_topup_note')
                        ->label(__('Note (optional)'))
                        ->rows(2)
                        ->maxLength(500)
                        ->visible(fn (Get $get): bool => (bool) $get('contribution_topup_enabled')),
                ]),
        ];
    }
}
