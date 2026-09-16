<?php

declare(strict_types=1);

namespace App\Services\Ocr;

use App\Contracts\ReceiptOcrDriver;
use App\Models\Tenant\FundPosting;
use App\Support\Ocr\ReceiptOcrResult;

/**
 * Deterministic OCR stub for local/testing: prefers posting fields, then structured comments.
 */
final class FakeReceiptOcrDriver implements ReceiptOcrDriver
{
    public function driver(): string
    {
        return 'fake';
    }

    public function extract(FundPosting $posting): ReceiptOcrResult
    {
        $amount = (float) $posting->amount;
        $date = $posting->posting_date?->toDateString();
        $reference = filled($posting->reference) ? (string) $posting->reference : null;
        $iban = null;
        $confidence = 0.7;

        $comments = (string) ($posting->comments ?? '');
        if (preg_match('/IBAN[:=]\s*([A-Z0-9]+)/i', $comments, $m) === 1) {
            $iban = strtoupper($m[1]);
            $confidence += 0.1;
        }

        if (preg_match('/OCR_AMOUNT[:=]\s*([0-9]+(?:\.[0-9]+)?)/i', $comments, $m) === 1) {
            $amount = (float) $m[1];
            $confidence += 0.1;
        }

        if (preg_match('/OCR_DATE[:=]\s*(\d{4}-\d{2}-\d{2})/i', $comments, $m) === 1) {
            $date = $m[1];
            $confidence += 0.05;
        }

        if (preg_match('/OCR_REF[:=]\s*(\S+)/i', $comments, $m) === 1) {
            $reference = $m[1];
            $confidence += 0.05;
        }

        return new ReceiptOcrResult(
            amount: $amount,
            date: $date,
            iban: $iban,
            reference: $reference,
            confidence: min(1.0, round($confidence, 2)),
            raw: [
                'driver' => 'fake',
                'source' => 'fund_posting_fields_and_comments',
            ],
        );
    }
}
