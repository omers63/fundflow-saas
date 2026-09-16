<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use App\Models\Tenant\GatewayPayment;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Support\Gateway\GatewayWebhookEvent;
use Illuminate\Support\Facades\Storage;

/**
 * Import SADAD payment confirmation CSV:
 * provider_ref,amount,status,paid_at,member_number
 */
final class SadadPaymentFileImportService
{
    public function __construct(
        private readonly GatewayPaymentService $payments,
    ) {
    }

    /**
     * @return array{imported: int, paid: int, failed: int, skipped: int, path: string}
     */
    public function import(string $contents, ?User $actor = null): array
    {
        $path = 'sadad-imports/' . now()->format('YmdHis') . '-' . uniqid() . '.csv';
        Storage::disk('local')->put($path, $contents);

        $lines = preg_split('/\R/', trim($contents)) ?: [];
        $imported = 0;
        $paid = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($lines as $index => $line) {
            $line = trim($line);
            if ($line === '' || ($index === 0 && str_contains(strtolower($line), 'provider_ref'))) {
                continue;
            }

            $cols = str_getcsv($line);
            if (count($cols) < 3) {
                $skipped++;

                continue;
            }

            [$providerRef, $amount, $status] = [$cols[0], (float) $cols[1], strtolower(trim($cols[2]))];
            $memberNumber = $cols[4] ?? null;

            $payment = GatewayPayment::query()->where('provider_ref', $providerRef)->first();

            if ($payment === null && filled($memberNumber)) {
                $member = Member::query()->where('member_number', $memberNumber)->first();
                if ($member === null) {
                    $skipped++;

                    continue;
                }

                $payment = GatewayPayment::query()->create([
                    'member_id' => $member->id,
                    'purpose' => GatewayPayment::PURPOSE_CONTRIBUTION,
                    'amount' => $amount,
                    'currency' => config('gateway.currency', 'SAR'),
                    'status' => GatewayPayment::STATUS_PENDING,
                    'provider' => GatewayPayment::PROVIDER_SADAD,
                    'provider_ref' => $providerRef,
                    'meta' => ['imported_from' => $path, 'actor_id' => $actor?->id],
                ]);
                $imported++;
            }

            if ($payment === null) {
                $skipped++;

                continue;
            }

            if ($status === 'paid' && $payment->isPending()) {
                $this->payments->handleWebhookEvent(
                    new GatewayWebhookEvent(
                        providerRef: (string) $payment->provider_ref,
                        status: 'paid',
                        amount: $amount,
                        payload: ['source' => 'sadad_file', 'path' => $path],
                    ),
                    app(SadadGatewayAdapter::class),
                );
                $paid++;
            } elseif (in_array($status, ['failed', 'cancelled'], true) && $payment->isPending()) {
                $payment->forceFill([
                    'status' => $status === 'cancelled'
                        ? GatewayPayment::STATUS_CANCELLED
                        : GatewayPayment::STATUS_FAILED,
                ])->save();
                $failed++;
            } else {
                $skipped++;
            }
        }

        return compact('imported', 'paid', 'failed', 'skipped', 'path');
    }
}
