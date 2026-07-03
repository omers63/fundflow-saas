<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use Filament\Resources\Pages\ViewRecord;
use Filament\Resources\RelationManagers\RelationManagerConfiguration;

trait FocusesLedgerTransactionOnViewRecord
{
    protected function bootstrapFocusedLedgerTransaction(string $transactionsRelationManagerClass): void
    {
        if (! $this instanceof ViewRecord) {
            return;
        }

        $transactionId = (int) request()->integer('transaction');

        if ($transactionId <= 0) {
            return;
        }

        foreach ($this->getRelationManagers() as $key => $manager) {
            $class = $manager instanceof RelationManagerConfiguration
                ? $manager->relationManager
                : $manager;

            if ($class !== $transactionsRelationManagerClass) {
                continue;
            }

            $this->activeRelationManager = (string) $key;

            return;
        }
    }
}
