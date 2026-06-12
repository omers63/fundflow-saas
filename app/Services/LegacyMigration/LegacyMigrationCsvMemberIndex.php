<?php

declare(strict_types=1);

namespace App\Services\LegacyMigration;

use App\Support\AssociativeCsv;

final class LegacyMigrationCsvMemberIndex
{
    /** @var array<string, array<string, string>> */
    private array $byNumber = [];

    /** @var array<string, array<string, string>> */
    private array $byName = [];

    public static function fromPath(string $absolutePath): self
    {
        $index = new self;

        foreach (AssociativeCsv::read($absolutePath) as $row) {
            $number = trim((string) ($row['member_number'] ?? ''));
            $name = trim((string) ($row['name'] ?? $row['member_name'] ?? ''));

            if ($number !== '') {
                $index->byNumber[$number] = $row;
            }

            if ($name !== '') {
                $index->byName[mb_strtolower($name)] = $row;
            }
        }

        return $index;
    }

    /**
     * @return array<string, string>|null
     */
    public function find(?string $memberNumber, ?string $memberName): ?array
    {
        $memberNumber = trim((string) $memberNumber);
        $memberName = trim((string) $memberName);

        if ($memberNumber !== '' && isset($this->byNumber[$memberNumber])) {
            return $this->byNumber[$memberNumber];
        }

        if ($memberName !== '') {
            return $this->byName[mb_strtolower($memberName)] ?? null;
        }

        return null;
    }

    public function isEmpty(): bool
    {
        return $this->byNumber === [] && $this->byName === [];
    }
}
