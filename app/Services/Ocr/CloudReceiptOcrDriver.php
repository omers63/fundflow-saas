<?php

declare(strict_types=1);

namespace App\Services\Ocr;

use App\Contracts\ReceiptOcrDriver;
use App\Models\Tenant\FundPosting;
use App\Support\Ocr\ReceiptOcrResult;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Cloud vision OCR via configurable HTTP endpoint.
 * Expects JSON: {amount, date, iban, reference, confidence}
 */
final class CloudReceiptOcrDriver implements ReceiptOcrDriver
{
    public function driver(): string
    {
        return 'cloud';
    }

    public function extract(FundPosting $posting): ReceiptOcrResult
    {
        $endpoint = (string) config('ocr.cloud.endpoint', '');
        $apiKey = (string) config('ocr.cloud.api_key', '');

        if ($endpoint === '') {
            throw new InvalidArgumentException(__('Cloud OCR endpoint is not configured.'));
        }

        $payload = [
            'posting_id' => $posting->id,
            'amount_hint' => (float) $posting->amount,
            'reference_hint' => $posting->reference,
            'attachment_path' => $posting->attachment_path ?? null,
            'comments' => $posting->comments,
        ];

        $request = Http::timeout((int) config('ocr.cloud.timeout', 20))
            ->acceptJson()
            ->asJson();

        if ($apiKey !== '') {
            $request = $request->withToken($apiKey);
        }

        $response = $request->post($endpoint, $payload);

        if (!$response->successful()) {
            throw new InvalidArgumentException(__('Cloud OCR request failed with HTTP :status.', [
                'status' => $response->status(),
            ]));
        }

        $data = $response->json();
        if (!is_array($data)) {
            throw new InvalidArgumentException(__('Cloud OCR returned an invalid payload.'));
        }

        return new ReceiptOcrResult(
            amount: isset($data['amount']) ? (float) $data['amount'] : (float) $posting->amount,
            date: isset($data['date']) ? (string) $data['date'] : $posting->posting_date?->toDateString(),
            iban: isset($data['iban']) ? strtoupper((string) $data['iban']) : null,
            reference: isset($data['reference']) ? (string) $data['reference'] : (string) ($posting->reference ?? ''),
            confidence: min(1.0, max(0.0, (float) ($data['confidence'] ?? 0.5))),
            raw: array_merge(['driver' => 'cloud'], $data),
        );
    }
}
