<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Models\Tenant\Transaction;
use Illuminate\Support\Facades\Auth;

trait OpensFocusedLedgerTransaction
{
    public bool $hasAutoOpenedFocusedTransaction = false;

    protected function resolveFocusTransactionId(): ?int
    {
        $transactionId = property_exists($this, 'focusTransactionId')
            ? $this->focusTransactionId
            : null;

        if ($transactionId === null || $transactionId <= 0) {
            $transactionId = request()->integer('transaction');
        }

        return $transactionId > 0 ? $transactionId : null;
    }

    public function bootedOpensFocusedLedgerTransaction(): void
    {
        $transactionId = $this->resolveFocusTransactionId();

        if ($transactionId === null || $this->hasAutoOpenedFocusedTransaction) {
            return;
        }

        if (! $this->focusedLedgerTransactionMatchesContext($transactionId)) {
            return;
        }

        $this->scheduleFocusedLedgerTransactionAction($transactionId);
    }

    protected function focusedLedgerTransactionActionName(): string
    {
        return (bool) Auth::guard('tenant')->user()?->is_admin ? 'edit' : 'view';
    }

    protected function scheduleFocusedLedgerTransactionAction(int $transactionId): void
    {
        $this->hasAutoOpenedFocusedTransaction = true;

        $action = $this->focusedLedgerTransactionActionName();

        $this->js('setTimeout(() => $wire.mountTableAction(\''.$action.'\', \''.$transactionId.'\'), 0)');
    }

    protected function openFocusedLedgerTransactionIfRequested(?int $accountId = null): void
    {
        $transactionId = $this->resolveFocusTransactionId();

        if ($transactionId === null) {
            return;
        }

        if (! $this->focusedLedgerTransactionMatchesContext($transactionId, $accountId)) {
            return;
        }

        $this->mountTableAction($this->focusedLedgerTransactionActionName(), (string) $transactionId);
    }

    protected function focusedLedgerTransactionMatchesContext(int $transactionId, ?int $accountId = null): bool
    {
        $transaction = Transaction::query()->find($transactionId);

        if ($transaction === null) {
            return false;
        }

        if ($accountId !== null) {
            return (int) $transaction->account_id === $accountId;
        }

        if (method_exists($this, 'getOwnerRecord')) {
            return (int) $transaction->account_id === (int) $this->getOwnerRecord()->getKey();
        }

        return true;
    }
}
