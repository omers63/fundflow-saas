<?php

declare(strict_types=1);

namespace App\Support\BankClearing;

use App\Filament\Tenant\Support\BankClearingTabRegistry;

enum BankClearingQueueFilter: string
{
    case All = 'all';

    case BankFile = 'bank_file';

    case Operations = 'operations';

    public static function fromMixed(?string $value): self
    {
        return match (BankClearingTabRegistry::normalizeQueueFilter($value)) {
            BankClearingTabRegistry::FILTER_BANK_FILE => self::BankFile,
            BankClearingTabRegistry::FILTER_OPERATIONS => self::Operations,
            default => self::All,
        };
    }
}
