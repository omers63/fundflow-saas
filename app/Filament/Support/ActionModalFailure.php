<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Actions\Action;
use Filament\Support\Exceptions\Halt;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ActionModalFailure
{
    /**
     * Surface an error inside the open action modal and keep it open (no toast).
     */
    public static function present(Action $action, string $message, ?string $heading = null): void
    {
        if (filled($heading)) {
            $action->modalHeading($heading);
        }

        $action->modalDescription(self::messageHtml($message));
        $action->modalIcon('heroicon-o-exclamation-circle');
        $action->modalIconColor('danger');
        $action->modalSubmitAction(false);
        $action->modalCancelActionLabel(__('Close'));

        throw new Halt;
    }

    /**
     * Run a service call; on expected business rule failures, show the modal error.
     *
     * @return bool True when the callback completed without a handled failure.
     */
    public static function attempt(Action $action, callable $callback, ?string $heading = null): bool
    {
        try {
            $callback();

            return true;
        } catch (InvalidArgumentException|RuntimeException $exception) {
            self::present($action, $exception->getMessage(), $heading);
        }
    }

    public static function attemptThrowable(Action $action, callable $callback, ?string $heading = null): bool
    {
        try {
            $callback();

            return true;
        } catch (Throwable $exception) {
            self::present($action, $exception->getMessage(), $heading);
        }
    }

    public static function messageHtml(string $message): Htmlable
    {
        return new HtmlString(
            '<div class="rounded-lg border border-danger-200 bg-danger-50 px-3 py-2 text-sm text-danger-700 dark:border-danger-800/50 dark:bg-danger-950/30 dark:text-danger-300">'
            .e($message)
            .'</div>'
        );
    }
}
