<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SmsImportTemplate extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'bank_name',
        'name',
        'is_default',
        'delimiter',
        'encoding',
        'has_header',
        'skip_rows',
        'sms_column',
        'date_column',
        'date_format',
        'amount_pattern',
        'date_pattern',
        'date_pattern_format',
        'reference_pattern',
        'credit_keywords',
        'debit_keywords',
        'default_transaction_type',
        'duplicate_match_fields',
        'duplicate_date_tolerance',
        'member_match_pattern',
        'member_match_field',
    ];

    protected $attributes = [
        'duplicate_match_fields' => '["date","amount","reference"]',
        'credit_keywords' => '["credited","received","deposit","credit"]',
        'debit_keywords' => '["debited","paid","purchase","debit","withdraw"]',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'has_header' => 'boolean',
            'skip_rows' => 'integer',
            'credit_keywords' => 'array',
            'debit_keywords' => 'array',
            'duplicate_match_fields' => 'array',
            'duplicate_date_tolerance' => 'integer',
        ];
    }

    public static function getDefault(?string $bankName = null): ?self
    {
        $query = static::query()->where('is_default', true);

        if ($bankName !== null && $bankName !== '') {
            $scoped = (clone $query)->where('bank_name', $bankName)->first();
            if ($scoped !== null) {
                return $scoped;
            }
        }

        return $query->first() ?? static::query()->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function toTemplateArray(): array
    {
        return [
            'bank_name' => $this->bank_name,
            'encoding' => $this->encoding ?? 'UTF-8',
            'delimiter' => $this->delimiter === '\t' ? "\t" : $this->delimiter,
            'has_header' => $this->has_header,
            'skip_rows' => $this->skip_rows,
            'sms_column' => $this->parseColumn($this->sms_column),
            'date_column' => filled($this->date_column) ? $this->parseColumn($this->date_column) : null,
            'date_format' => $this->date_format,
            'amount_pattern' => $this->amount_pattern,
            'date_pattern' => $this->date_pattern,
            'date_pattern_format' => $this->date_pattern_format,
            'reference_pattern' => $this->reference_pattern,
            'credit_keywords' => $this->credit_keywords ?? [],
            'debit_keywords' => $this->debit_keywords ?? [],
            'default_transaction_type' => $this->default_transaction_type ?? 'credit',
            'duplicate_match_fields' => $this->duplicate_match_fields ?? ['date', 'amount', 'reference'],
            'duplicate_date_tolerance' => $this->duplicate_date_tolerance ?? 0,
            'member_match_pattern' => $this->member_match_pattern,
            'member_match_field' => $this->member_match_field ?? 'member_number',
        ];
    }

    private function parseColumn(string $value): int|string
    {
        if (is_numeric($value)) {
            return (int) $value;
        }

        return $value;
    }
}
