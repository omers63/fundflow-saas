<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\MemberRequests\Schemas;

use App\Filament\Support\MemberTableColumns;
use App\Models\Tenant\MemberRequest;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class MemberRequestViewInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->schema([
                Section::make(__('Request'))
                    ->compact()
                    ->secondary()
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        TextEntry::make('requester.name')
                            ->label(__('Member'))
                            ->url(fn (MemberRequest $record): ?string => $record->requester
                                ? MemberTableColumns::memberRecordUrl($record->requester)
                                : null),
                        TextEntry::make('requester.member_number')
                            ->label(__('Member #')),
                        TextEntry::make('type')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => MemberRequest::typeLabel($state)),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => MemberRequest::statusOptions()[$state] ?? $state)
                            ->color(fn (string $state): string => match ($state) {
                                MemberRequest::STATUS_PENDING => 'warning',
                                MemberRequest::STATUS_APPROVED => 'success',
                                MemberRequest::STATUS_REJECTED => 'danger',
                                default => 'gray',
                            }),
                        TextEntry::make('created_at')
                            ->label(__('Submitted'))
                            ->dateTime(),
                        TextEntry::make('reviewedBy.name')
                            ->label(__('Reviewed by'))
                            ->placeholder(__('—')),
                        TextEntry::make('reviewed_at')
                            ->dateTime()
                            ->placeholder(__('—')),
                    ]),
                Section::make(__('Summary'))
                    ->compact()
                    ->secondary()
                    ->schema([
                        TextEntry::make('details_display')
                            ->label(__('Details'))
                            ->getStateUsing(fn (MemberRequest $record): string => $record->describePayload())
                            ->columnSpanFull(),
                        TextEntry::make('admin_note')
                            ->label(__('Admin note'))
                            ->placeholder(__('—'))
                            ->visible(fn (MemberRequest $record): bool => filled($record->admin_note))
                            ->columnSpanFull(),
                    ]),
                Section::make(__('Payload'))
                    ->compact()
                    ->secondary()
                    ->collapsed()
                    ->schema([
                        TextEntry::make('payload_text')
                            ->label(__('Raw payload'))
                            ->getStateUsing(fn (MemberRequest $record): string => $record->payloadAsPlainText())
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
