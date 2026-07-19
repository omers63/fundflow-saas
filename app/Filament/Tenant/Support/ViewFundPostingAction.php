<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Support;

use App\Filament\Support\ViewActions\ViewFundPostingAction as SharedViewFundPostingAction;
use App\Models\Tenant\FundPosting;
use Filament\Actions\ViewAction;

final class ViewFundPostingAction
{
    public static function make(): ViewAction
    {
        return TenantPortalViewModal::apply(
            ViewAction::make()
                ->icon('heroicon-o-eye')
                ->modalHeading(fn (FundPosting $record): string => __('Deposit — :name', [
                    'name' => $record->member->name,
                ]))
                ->modalContent(fn (FundPosting $record) => TenantPortalViewModal::content(
                    self::sections($record),
                )),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function sections(FundPosting $record): array
    {
        $sections = SharedViewFundPostingAction::memberPortalSections($record);
        $sections[0]['hero']['label'] = __('Deposit').' — '.$record->member->name;

        return $sections;
    }
}
