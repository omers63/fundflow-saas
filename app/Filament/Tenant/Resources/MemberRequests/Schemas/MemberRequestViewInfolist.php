<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\MemberRequests\Schemas;

use App\Filament\Support\MemberRequestViewSections;
use App\Models\Tenant\MemberRequest;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Schema;

final class MemberRequestViewInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->schema([
                SchemaView::make('filament.tenant.partials.view-record-modal')
                    ->viewData(fn (MemberRequest $record): array => [
                        'sections' => MemberRequestViewSections::forAdmin($record),
                    ]),
            ]);
    }
}
