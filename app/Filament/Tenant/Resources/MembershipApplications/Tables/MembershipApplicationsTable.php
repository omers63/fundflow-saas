<?php

namespace App\Filament\Tenant\Resources\MembershipApplications\Tables;

use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Filament\Tenant\Resources\MembershipApplications\MembershipApplicationResource;
use App\Models\Tenant\Member;
use App\Models\Tenant\MembershipApplication;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;

class MembershipApplicationsTable
{
    public static function configure(Table $table): Table
    {
        return TableGrouping::apply($table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->url(fn (MembershipApplication $record): string => MembershipApplicationResource::getUrl('edit', ['record' => $record])),
                TextColumn::make('email')
                    ->searchable(),
                TextColumn::make('phone'),
                TextColumn::make('application_type')
                    ->label(__('Type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),
                TextColumn::make('message')
                    ->limit(40),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'approved' => 'success',
                        'rejected' => 'danger',
                    }),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                    ]),
                DateColumnRangeFilter::make('created_at', 'Submitted'),
            ])
            ->recordUrl(fn (MembershipApplication $record): string => MembershipApplicationResource::getUrl('edit', ['record' => $record]))
            ->recordActions(TableRecordActionGroups::wrap([
                EditAction::make(),
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->hidden(fn ($record) => $record->status !== 'pending')
                    ->action(function ($record, Component $livewire) {
                        $user = User::create([
                            'name' => $record->name,
                            'email' => $record->email,
                            'password' => $record->password,
                            'is_admin' => false,
                        ]);

                        $memberNumber = 'MEM-'.str_pad((string) (Member::count() + 1), 4, '0', STR_PAD_LEFT);

                        $member = Member::create([
                            'user_id' => $user->id,
                            'member_number' => $memberNumber,
                            'name' => $record->name,
                            'email' => $record->email,
                            'household_email' => $record->email,
                            'phone' => $record->mobile_phone ?? $record->phone,
                            'monthly_contribution_amount' => 0,
                            'joined_at' => now(),
                            'status' => 'active',
                        ]);

                        app(AccountingService::class)->createMemberAccounts($member);

                        $record->update([
                            'status' => 'approved',
                            'reviewed_at' => now(),
                        ]);

                        Notification::make()
                            ->title(__('Member :name created from application', ['name' => $member->name]))
                            ->success()
                            ->send();

                        MembershipApplicationResource::dispatchInsightsRefresh($livewire);
                    }),
                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->hidden(fn ($record) => $record->status !== 'pending')
                    ->action(function ($record, Component $livewire) {
                        $record->update([
                            'status' => 'rejected',
                            'reviewed_at' => now(),
                        ]);
                        Notification::make()->title(__('Application rejected'))->warning()->send();

                        MembershipApplicationResource::dispatchInsightsRefresh($livewire);
                    }),
                DeleteAction::make()
                    ->after(fn (Component $livewire) => MembershipApplicationResource::dispatchInsightsRefresh($livewire)),
            ]))
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('approveSelected')
                        ->label(__('Approve'))
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function (Collection $records, Component $livewire) {
                            $approved = 0;
                            foreach ($records as $record) {
                                if ($record->status !== 'pending') {
                                    continue;
                                }
                                $user = User::create([
                                    'name' => $record->name,
                                    'email' => $record->email,
                                    'password' => $record->password,
                                    'is_admin' => false,
                                ]);

                                $memberNumber = 'MEM-'.str_pad((string) (Member::count() + 1), 4, '0', STR_PAD_LEFT);

                                $member = Member::create([
                                    'user_id' => $user->id,
                                    'member_number' => $memberNumber,
                                    'name' => $record->name,
                                    'email' => $record->email,
                                    'household_email' => $record->email,
                                    'phone' => $record->mobile_phone ?? $record->phone,
                                    'monthly_contribution_amount' => 0,
                                    'joined_at' => now(),
                                    'status' => 'active',
                                ]);

                                app(AccountingService::class)->createMemberAccounts($member);

                                $record->update([
                                    'status' => 'approved',
                                    'reviewed_at' => now(),
                                ]);
                                $approved++;
                            }
                            Notification::make()
                                ->title(__(':count application(s) approved', ['count' => $approved]))
                                ->success()
                                ->send();

                            MembershipApplicationResource::dispatchInsightsRefresh($livewire);
                        }),
                    BulkAction::make('rejectSelected')
                        ->label(__('Reject'))
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(function (Collection $records, Component $livewire) {
                            $rejected = 0;
                            foreach ($records as $record) {
                                if ($record->status !== 'pending') {
                                    continue;
                                }
                                $record->update([
                                    'status' => 'rejected',
                                    'reviewed_at' => now(),
                                ]);
                                $rejected++;
                            }
                            Notification::make()
                                ->title(__(':count application(s) rejected', ['count' => $rejected]))
                                ->warning()
                                ->send();

                            MembershipApplicationResource::dispatchInsightsRefresh($livewire);
                        }),
                    DeleteBulkAction::make()
                        ->after(fn (Component $livewire) => MembershipApplicationResource::dispatchInsightsRefresh($livewire)),
                    TableToolbar::refreshBulkAction(),
                ]),
            ])
            ->defaultSort('created_at', 'desc'),
            TableGrouping::membershipApplications());
    }
}
