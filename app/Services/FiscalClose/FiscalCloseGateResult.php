<?php

declare(strict_types=1);

namespace App\Services\FiscalClose;

final readonly class FiscalCloseGateResult
{
    public const STATUS_PASS = 'pass';

    public const STATUS_FAIL = 'fail';

    public const STATUS_WARN = 'warn';

    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public string $code,
        public string $label,
        public string $status,
        public string $message,
        public array $details = [],
        public ?int $count = null,
    ) {}

    public function isPass(): bool
    {
        return $this->status === self::STATUS_PASS;
    }

    public function isFail(): bool
    {
        return $this->status === self::STATUS_FAIL;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'label' => $this->label,
            'status' => $this->status,
            'message' => $this->message,
            'details' => $this->details,
            'count' => $this->count,
        ];
    }
}
