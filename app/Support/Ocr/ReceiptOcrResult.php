<?php

declare(strict_types=1);

namespace App\Support\Ocr;

final readonly class ReceiptOcrResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public ?float $amount,
        public ?string $date,
        public ?string $iban,
        public ?string $reference,
        public float $confidence,
        public array $raw = [],
    ) {}
}
