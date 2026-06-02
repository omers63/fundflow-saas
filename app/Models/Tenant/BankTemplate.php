<?php

namespace App\Models\Tenant;

use App\Support\ImportDateFormats;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class BankTemplate extends Model
{
    protected $fillable = [
        'name',
        'encoding',
        'delimiter',
        'has_header',
        'skip_rows',
        'date_format',
        'date_column',
        'amount_column',
        'amount_mode',
        'credit_column',
        'debit_column',
        'extra_columns',
        'duplicate_fields',
        'duplicate_date_tolerance',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'has_header' => 'boolean',
            'skip_rows' => 'integer',
            'is_default' => 'boolean',
            'extra_columns' => 'array',
            'duplicate_fields' => 'array',
            'duplicate_date_tolerance' => 'integer',
        ];
    }

    /**
     * Convert this model to the template array format used by BankImportService.
     *
     * @return array<string, mixed>
     */
    public function toTemplateArray(): array
    {
        $columns = [
            'date' => $this->parseColumn($this->date_column),
        ];

        if ($this->amount_mode === 'split') {
            $columns['credit'] = $this->credit_column ? $this->parseColumn($this->credit_column) : null;
            $columns['debit'] = $this->debit_column ? $this->parseColumn($this->debit_column) : null;
        } else {
            $columns['amount'] = $this->parseColumn($this->amount_column);
        }

        $extraColumns = $this->extra_columns ?? [];
        if (($this->amount_mode ?? 'single') === 'single' && $extraColumns === []) {
            $extraColumns = self::defaultExtraColumns();
        }

        foreach ($extraColumns as $mapping) {
            $key = $mapping['key'] ?? null;
            $col = $mapping['column'] ?? null;
            if ($key && $col !== null && $col !== '') {
                $columns[$key] = $this->parseColumn($col);
            }
        }

        return [
            'encoding' => $this->encoding ?? 'UTF-8',
            'delimiter' => $this->delimiter,
            'has_header' => $this->has_header,
            'skip_rows' => $this->skip_rows,
            'date_formats' => $this->date_format,
            'date_format' => $this->date_format,
            'amount_mode' => $this->amount_mode ?? 'single',
            'columns' => $columns,
            'duplicate_fields' => $this->duplicate_fields ?? ['date', 'amount', 'description', 'reference'],
            'duplicate_date_tolerance' => $this->duplicate_date_tolerance ?? 0,
        ];
    }

    private function parseColumn(string $value): int|string
    {
        if (is_numeric($value)) {
            return (int) $value;
        }

        return $value;
    }

    /**
     * Default optional column mappings for single-amount CSV templates (0-based indices).
     *
     * @return array<int, array{key: string, column: string}>
     */
    public static function defaultExtraColumns(): array
    {
        return [
            ['key' => 'description', 'column' => '1'],
            ['key' => 'reference', 'column' => '3'],
        ];
    }

    public static function getDefault(): ?self
    {
        return static::where('is_default', true)->first()
            ?? static::first();
    }

    /**
     * @return Attribute<array<int, string>, string>
     */
    protected function dateFormat(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): array => ImportDateFormats::normalize($value),
            set: fn (array|string|null $value): string => json_encode(ImportDateFormats::normalize($value)),
        );
    }
}
