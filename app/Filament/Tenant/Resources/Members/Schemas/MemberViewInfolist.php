<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\Members\Schemas;

use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class MemberViewInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $currency = Setting::get('general', 'currency', 'USD');

        return $schema
            ->columns(1)
            ->schema([
                self::detailSection(__('Membership'), __('Core profile and contribution settings — balances and journey are in the summary panel above.'))
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        TextEntry::make('member_number')
                            ->label(__('Member #')),
                        TextEntry::make('name'),
                        TextEntry::make('email')
                            ->copyable(),
                        TextEntry::make('phone')
                            ->placeholder(__('—')),
                        TextEntry::make('monthly_contribution_amount')
                            ->label(__('Monthly contribution'))
                            ->money($currency),
                        TextEntry::make('joined_at')
                            ->date(),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn(string $state, Member $record): string => $record->adminStatusLabel())
                            ->color(fn(Member $record): string => $record->adminStatusBadgeColor()),
                        TextEntry::make('parent.name')
                            ->label(__('Parent member'))
                            ->placeholder(__('Independent')),
                    ]),
                self::detailSection(__('Portal & household'), __('Login and household linkage'))
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        TextEntry::make('household_email')
                            ->label(__('Household email'))
                            ->placeholder(__('—')),
                        IconEntry::make('is_separated')
                            ->label(__('Separated household'))
                            ->boolean(),
                        IconEntry::make('direct_login_enabled')
                            ->label(__('Direct login enabled'))
                            ->boolean(),
                        TextEntry::make('contribution_arrears_cutoff_date')
                            ->label(__('Contribution arrears cut-off'))
                            ->date()
                            ->placeholder(__('—'))
                            ->visible(fn (Member $record): bool => $record->contribution_arrears_cutoff_date !== null),
                    ]),
            ]);
    }

    private static function detailSection(string $heading, ?string $description = null): Section
    {
        $section = Section::make($heading)
            ->compact()
            ->secondary()
            ->columnSpanFull();

        if ($description !== null) {
            $section->description($description);
        }

        return $section;
    }
}
