<?php

declare(strict_types=1);

namespace App\Services\LegacyMigration;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * Persist / decode structured legacy import success and failure payloads for the wizard UI.
 */
final class LegacyImportFailureRecorder
{
    public const MEMBERS_FAILURE_SETTING = 'members_import_failure';

    public const LOANS_FAILURE_SETTING = 'loans_import_failure';

    public const CLASSIFY_FAILURE_SETTING = 'classify_failure';

    public const APPLY_FAILURE_SETTING = 'apply_failure';

    /**
     * @param  array{created?: int, skipped?: int, failed?: int, contributions?: int, loan_repayments?: int, ignored?: int, errors?: list<string>}|null  $result
     * @return array{
     *     status: 'failed',
     *     section: string,
     *     stage: string,
     *     message: string,
     *     exception: string,
     *     location: string|null,
     *     created: int,
     *     skipped: int,
     *     failed: int,
     *     contributions: int,
     *     loan_repayments: int,
     *     ignored: int,
     *     errors: list<string>,
     *     errors_total: int,
     *     errors_truncated: bool,
     *     recorded_at: string,
     * }
     */
    public static function buildPayload(
        string $section,
        string $message,
        ?\Throwable $exception = null,
        ?array $result = null,
        string $stage = 'import',
    ): array {
        $errors = self::normalizeErrors($result['errors'] ?? []);

        if ($errors === [] && filled($message)) {
            $errors = [$message];
        }

        $totalErrors = count($errors);
        $truncated = $totalErrors > 50;
        $shown = $truncated ? array_slice($errors, 0, 50) : $errors;

        $location = null;
        if ($exception !== null) {
            $location = basename($exception->getFile()).':'.$exception->getLine();
        }

        return [
            'status' => 'failed',
            'section' => $section,
            'stage' => $stage,
            'message' => $message,
            'exception' => $exception !== null ? class_basename($exception) : 'ImportFailure',
            'location' => $location,
            'created' => (int) ($result['created'] ?? 0),
            'skipped' => (int) ($result['skipped'] ?? 0),
            'failed' => (int) ($result['failed'] ?? max(0, $totalErrors)),
            'contributions' => (int) ($result['contributions'] ?? 0),
            'loan_repayments' => (int) ($result['loan_repayments'] ?? 0),
            'ignored' => (int) ($result['ignored'] ?? 0),
            'errors' => $shown,
            'errors_total' => $totalErrors,
            'errors_truncated' => $truncated,
            'recorded_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  list<mixed>|mixed  $errors
     * @return list<string>
     */
    public static function normalizeErrors(mixed $errors): array
    {
        if (! is_array($errors)) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                static fn (mixed $error): string => trim((string) $error),
                $errors,
            ),
            static fn (string $error): bool => $error !== '',
        ));
    }

    /**
     * @param  array{
     *     wire_key: string,
     *     status: string,
     *     title_failed: string,
     *     title_warning: string,
     *     title_success: string,
     *     message?: string|null,
     *     stage?: string|null,
     *     location?: string|null,
     *     stats?: list<array{label: string, value: int|string}>,
     *     errors?: list<string>,
     *     errors_total?: int,
     *     errors_truncated?: bool,
     *     extra?: list<array{title: string, body: string}>,
     * }  $panel
     * @return array<string, mixed>|null
     */
    public static function finalizePanel(array $panel): ?array
    {
        $status = (string) ($panel['status'] ?? 'idle');
        $errors = self::normalizeErrors($panel['errors'] ?? []);
        $message = filled($panel['message'] ?? null) ? (string) $panel['message'] : null;
        $stats = $panel['stats'] ?? [];

        if ($status === 'idle' && $errors === [] && $message === null && $stats === []) {
            return null;
        }

        $hasErrors = $errors !== [];
        $isFailed = $status === 'failed';
        $isWarning = ! $isFailed && ($hasErrors || $status === 'warning');

        $title = match (true) {
            $isFailed => (string) $panel['title_failed'],
            $isWarning => (string) $panel['title_warning'],
            default => (string) $panel['title_success'],
        };

        return [
            'wire_key' => (string) $panel['wire_key'],
            'status' => $status,
            'tone' => $isFailed ? 'danger' : ($isWarning ? 'warning' : 'success'),
            'title' => $title,
            'message' => $message,
            'stage' => filled($panel['stage'] ?? null) ? (string) $panel['stage'] : null,
            'stage_label' => filled($panel['stage'] ?? null)
                ? self::stageLabel(['stage' => (string) $panel['stage']])
                : null,
            'location' => filled($panel['location'] ?? null) ? (string) $panel['location'] : null,
            'stats' => $stats,
            'errors' => $errors,
            'errors_total' => (int) ($panel['errors_total'] ?? count($errors)),
            'errors_truncated' => (bool) ($panel['errors_truncated'] ?? false),
            'extra' => $panel['extra'] ?? [],
            'default_open' => $isFailed || $isWarning,
            'errors_default_open' => $isFailed || $isWarning,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function stageLabel(array $payload): string
    {
        return match ((string) ($payload['stage'] ?? '')) {
            'authorization' => __('Authorization'),
            'validation' => __('CSV validation'),
            'import' => __('Row import'),
            'completeness_check' => __('Post-import completeness check'),
            'storage' => __('File storage'),
            'classification' => __('Payment classification'),
            'apply' => __('Payment apply'),
            default => __('Import'),
        };
    }

    public static function exceptionStage(\Throwable $exception, string $default = 'import'): string
    {
        return match (true) {
            $exception instanceof IncompleteLegacyImportException => $exception->stage,
            $exception instanceof AuthorizationException => 'authorization',
            $exception instanceof \InvalidArgumentException => 'validation',
            default => $default,
        };
    }
}
