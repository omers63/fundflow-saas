<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\SmsImportTemplates\Schemas;

use App\Filament\Support\SmsImportTemplateFieldsets;
use Filament\Schemas\Schema;

final class SmsImportTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->schema(SmsImportTemplateFieldsets::forSettingsRepeater());
    }
}
