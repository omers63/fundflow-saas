<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

/**
 * Lazy section folds used by dashboard / workspace pages and insight widgets.
 * Expand calls {@see unfoldSection()} so expensive chart payloads load only when opened.
 */
trait InteractsWithUnfoldedSections
{
    /**
     * @var array<string, bool>
     */
    public array $unfoldedSections = [];

    public function unfoldSection(string $section): void
    {
        $this->unfoldedSections = [
            ...$this->unfoldedSections,
            $section => true,
        ];
    }

    public function isSectionUnfolded(string $section): bool
    {
        return (bool) ($this->unfoldedSections[$section] ?? false);
    }
}
