<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\Loans\Widgets;

use App\Filament\Tenant\Widgets\LoanInsightsWidget;

class LoanViewInsights extends LoanInsightsWidget
{
    public string $context = 'loan_detail';

    protected ?string $pollingInterval = null;
}
