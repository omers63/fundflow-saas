<?php

declare(strict_types=1);

namespace App\Services\Ocr;

use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\SmsTransaction;
use App\Services\FundPostingService;
use App\Support\DepositOcrSettings;
use Illuminate\Support\Carbon;

final class ThreeWayDepositMatchService
{
    public function __construct(
        private readonly ReceiptOcrService $ocr,
        private readonly FundPostingService $fundPostings,
    ) {}

    /**
     * @return array{
     *     confidence: float,
     *     matched: bool,
     *     bank_transaction_id: int|null,
     *     sms_transaction_id: int|null,
     *     reasons: list<string>
     * }
     */
    public function evaluate(FundPosting $posting): array
    {
        $extraction = $posting->ocr_extraction;
        if (! is_array($extraction) || $posting->ocr_confidence === null) {
            $this->ocr->extractAndStore($posting);
            $posting->refresh();
            $extraction = $posting->ocr_extraction ?? [];
        }

        $amount = (float) ($extraction['amount'] ?? $posting->amount);
        $date = (string) ($extraction['date'] ?? $posting->posting_date?->toDateString() ?? '');
        $reference = (string) ($extraction['reference'] ?? $posting->reference ?? '');
        $tolerance = DepositOcrSettings::amountTolerance();
        $reasons = [];

        $bank = BankTransaction::query()
            ->uncleared()
            ->where('amount', '>', 0)
            ->whereBetween('amount', [$amount - $tolerance, $amount + $tolerance])
            ->when($date !== '', function ($query) use ($date): void {
                $day = Carbon::parse($date);
                $query->whereBetween('transaction_date', [
                    $day->copy()->subDay()->toDateString(),
                    $day->copy()->addDay()->toDateString(),
                ]);
            })
            ->when($reference !== '', fn ($query) => $query->where(function ($inner) use ($reference): void {
                $inner->where('reference', $reference)
                    ->orWhere('description', 'like', '%'.$reference.'%');
            }))
            ->when($posting->member_id, fn ($query) => $query->where(function ($inner) use ($posting): void {
                $inner->whereNull('member_id')->orWhere('member_id', $posting->member_id);
            }))
            ->orderByDesc('id')
            ->first();

        $sms = null;
        if (class_exists(SmsTransaction::class)) {
            $sms = SmsTransaction::query()
                ->whereNull('posted_at')
                ->whereBetween('amount', [$amount - $tolerance, $amount + $tolerance])
                ->when($posting->member_id, fn ($query) => $query->where(function ($inner) use ($posting): void {
                    $inner->whereNull('member_id')->orWhere('member_id', $posting->member_id);
                }))
                ->orderByDesc('id')
                ->first();
        }

        $score = (float) ($posting->ocr_confidence ?? 0);

        if ($bank !== null) {
            $score += 0.1;
            $reasons[] = __('Bank line matched');
        } else {
            $reasons[] = __('No matching bank line');
        }

        if ($sms !== null) {
            $score += 0.1;
            $reasons[] = __('SMS line matched');
        } else {
            $reasons[] = __('No matching SMS line');
        }

        $score = min(1.0, round($score, 2));
        $matched = $bank !== null
            && $sms !== null
            && $score >= DepositOcrSettings::confidenceThreshold();

        $posting->forceFill([
            'ocr_match_status' => $matched ? 'matched' : 'needs_review',
        ])->save();

        return [
            'confidence' => $score,
            'matched' => $matched,
            'bank_transaction_id' => $bank?->id,
            'sms_transaction_id' => $sms?->id,
            'reasons' => $reasons,
        ];
    }

    public function maybeAutoAccept(FundPosting $posting): bool
    {
        if (! DepositOcrSettings::autoAcceptEnabled()) {
            return false;
        }

        if ($posting->status !== 'pending') {
            return false;
        }

        $result = $this->evaluate($posting);

        if (! $result['matched']) {
            return false;
        }

        // Amount guard: never auto-accept when OCR amount drifts beyond tolerance.
        $ocrAmount = (float) (($posting->fresh()->ocr_extraction['amount'] ?? $posting->amount));
        if (abs($ocrAmount - (float) $posting->amount) > DepositOcrSettings::amountTolerance()) {
            $posting->forceFill(['ocr_match_status' => 'amount_mismatch'])->save();

            return false;
        }

        $this->fundPostings->accept($posting, null, __('Auto-accepted via three-way OCR match'));

        return true;
    }
}
