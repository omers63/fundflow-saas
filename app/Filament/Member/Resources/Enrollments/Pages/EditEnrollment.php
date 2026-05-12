<?php

namespace App\Filament\Member\Resources\Enrollments\Pages;

use App\Filament\Member\Resources\Enrollments\EnrollmentResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Relaticle\Comments\Filament\Actions\CommentsAction;

class EditEnrollment extends EditRecord
{
    protected static string $resource = EnrollmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CommentsAction::make(),
            DeleteAction::make(),
        ];
    }
}
