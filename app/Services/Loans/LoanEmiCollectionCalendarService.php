<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Models\Tenant\LoanInstallment;
use App\Services\CollectionCalendarService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * @deprecated Use {@see CollectionCalendarService} instead.
 */
final class LoanEmiCollectionCalendarService extends CollectionCalendarService
{
    /**
     * @return Collection<int, LoanInstallment>
     */
    public function installmentsForDate(Carbon $date): Collection
    {
        return $this->emisForDate($date->toDateString());
    }
}
