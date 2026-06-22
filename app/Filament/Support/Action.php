<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Concerns\FormatsFilamentLabel;
use Filament\Actions\Action as BaseAction;

class Action extends BaseAction
{
    use FormatsFilamentLabel;

    protected bool|\Closure $isLongRunning = false;

    protected string|\Closure|null $longRunningMessage = null;

    public function longRunning(bool|\Closure $condition = true): static
    {
        $this->isLongRunning = $condition;

        return $this;
    }

    public function longRunningMessage(string|\Closure|null $message): static
    {
        $this->longRunningMessage = $message;

        return $this;
    }

    public function isLongRunning(): bool
    {
        return (bool) $this->evaluate($this->isLongRunning);
    }

    public function getLongRunningMessage(): ?string
    {
        $message = $this->evaluate($this->longRunningMessage);

        return filled($message) ? (string) $message : null;
    }
}
