<?php

declare(strict_types=1);

namespace App\Filament\Member\Widgets;

use App\Filament\Member\Resources\MyFundPostings\Tables\MyFundPostingsTable;
use App\Models\Tenant\FundPosting;
use App\Support\Tenant\CurrentMember;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class MemberCashDepositsTableWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    public function getHeading(): ?string
    {
        return __('Deposit requests');
    }

    protected function getTableQuery(): Builder
    {
        $memberId = CurrentMember::id();

        if ($memberId === null) {
            return FundPosting::query()->whereRaw('1 = 0');
        }

        return FundPosting::query()->where('member_id', $memberId);
    }

    public function table(Table $table): Table
    {
        return MyFundPostingsTable::configure(
            $table
                ->heading(__('Deposit requests'))
                ->description(__('Fund postings you submitted for admin review.'))
        );
    }
}
