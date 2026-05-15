<?php

namespace App\Filament\Tenant\Resources\MembershipApplications\Tables;

use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Tenant\Resources\MembershipApplications\MembershipApplicationResource;
use App\Models\Tenant\Member;
use App\Models\Tenant\MembershipApplication;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class MembershipApplicationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
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
            ->recordActions([
                EditAction::make(),
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->hidden(fn ($record) => $record->status !== 'pending')
                    ->action(function ($record) {
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
                    }),
                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->hidden(fn ($record) => $record->status !== 'pending')
                    ->action(function ($record) {
                        $record->update([
                            'status' => 'rejected',
                            'reviewed_at' => now(),
                        ]);
                        Notification::make()->title(__('Application rejected'))->warning()->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
