<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Tenant\Transaction;
use App\Services\ReconciliationService;

class TransactionObserver
{
    public function __construct(
        protected ReconciliationService $reconciliation,
    ) {}

    public function created(Transaction $transaction): void
    {
        $this->reconciliation->onTransactionPosted($transaction);
    }
}
