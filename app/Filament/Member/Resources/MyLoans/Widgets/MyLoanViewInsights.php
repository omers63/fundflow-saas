<?php

declare(strict_types=1);

namespace App\Filament\Member\Resources\MyLoans\Widgets;

use App\Filament\Member\Widgets\MemberLoanInsightsWidget;

class MyLoanViewInsights extends MemberLoanInsightsWidget
{
    public string $context = 'loan_detail';
}
