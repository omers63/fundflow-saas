<?php

declare(strict_types=1);

namespace App\Filament\Member\Widgets;

use App\Filament\Member\Support\SubmitSupportRequestAction;
use App\Filament\Member\Support\ViewSupportRequestAction;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Models\Tenant\SupportRequest;
use App\Support\Tenant\CurrentMember;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

class MySupportRequestsTableWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    public function getHeading(): ?string
    {
        return __('Support requests');
    }

    protected function getTableQuery(): Builder|Relation|null
    {
        $user = auth('tenant')->user();
        $memberId = CurrentMember::id();

        if ($user === null) {
            return SupportRequest::query()->whereRaw('1 = 0');
        }

        return SupportRequest::query()
            ->where(function (Builder $query) use ($user, $memberId): void {
                $query->where('user_id', $user->id);

                if ($memberId !== null) {
                    $query->orWhere('member_id', $memberId);
                }
            });
    }

    public function table(Table $table): Table
    {
        return TableRecordActionGroups::apply(
            $table
                ->heading(__('Support requests'))
                ->description(__('Questions and account issues sent to fund administrators.'))
                ->columns([
                    TextColumn::make('subject')
                        ->label(__('Subject'))
                        ->searchable()
                        ->wrap(),
                    TextColumn::make('category')
                        ->label(__('Category'))
                        ->badge()
                        ->formatStateUsing(fn(string $state): string => SupportRequest::categoryLabel($state)),
                    TextColumn::make('status')
                        ->badge()
                        ->formatStateUsing(fn(string $state): string => SupportRequest::statusOptions()[$state] ?? $state)
                        ->color(fn(string $state): string => SupportRequest::statusColor($state)),
                    TextColumn::make('created_at')
                        ->label(__('Submitted'))
                        ->dateTime()
                        ->sortable(),
                ])
                ->filters([
                    SelectFilter::make('status')
                        ->options(SupportRequest::statusOptions()),
                ])
                ->defaultSort('created_at', 'desc')
                ->emptyStateHeading(__('No support requests'))
                ->emptyStateDescription(__('Use Submit request above to message fund administrators.'))
                ->headerActions([
                    SubmitSupportRequestAction::make(),
                ])
                ->toolbarActions([
                    BulkActionGroup::make([
                        TableToolbar::refreshBulkAction(),
                    ]),
                ]),
            [ViewSupportRequestAction::make()],
        );
    }
}
