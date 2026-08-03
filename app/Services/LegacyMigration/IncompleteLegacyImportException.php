<?php

declare(strict_types=1);

namespace App\Services\LegacyMigration;

use RuntimeException;
use Throwable;

/**
 * Thrown when a legacy import step cannot finish cleanly but partial counters/errors are available.
 *
 * @phpstan-type MembersResult array{created: int, skipped: int, failed: int, errors: list<string>}
 * @phpstan-type LoansResult array{created: int, failed: int, errors: list<string>}
 */
final class IncompleteLegacyImportException extends RuntimeException
{
    /**
     * @param  MembersResult|LoansResult  $result
     */
    public function __construct(
        string $message,
        public readonly array $result,
        public readonly string $section = 'members',
        public readonly string $stage = 'completeness_check',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
