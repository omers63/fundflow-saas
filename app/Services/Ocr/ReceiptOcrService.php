<?php

declare(strict_types=1);

namespace App\Services\Ocr;

use App\Contracts\ReceiptOcrDriver;
use App\Models\Tenant\FundPosting;
use App\Support\DepositOcrSettings;
use App\Support\Ocr\ReceiptOcrResult;
use InvalidArgumentException;

final class ReceiptOcrService
{
    public function __construct(
        private readonly FakeReceiptOcrDriver $fake,
    ) {}

    public function driver(): ReceiptOcrDriver
    {
        return match (DepositOcrSettings::driver()) {
            'fake' => $this->fake,
            default => throw new InvalidArgumentException(__('Unsupported OCR driver.')),
        };
    }

    public function extractAndStore(FundPosting $posting): ReceiptOcrResult
    {
        $result = $this->driver()->extract($posting);

        $posting->forceFill([
            'ocr_extraction' => [
                'amount' => $result->amount,
                'date' => $result->date,
                'iban' => $result->iban,
                'reference' => $result->reference,
                'raw' => $result->raw,
            ],
            'ocr_confidence' => $result->confidence,
        ])->save();

        return $result;
    }
}
