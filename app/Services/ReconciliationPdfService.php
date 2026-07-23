<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\ReconciliationSnapshot;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReconciliationPdfService
{
    /**
     * Livewire only triggers file downloads for {@see StreamedResponse} / BinaryFileResponse — DomPDF's
     * {@see Pdf::download()} returns a plain Response, so wire:click never receives a download effect.
     */
    public function download(ReconciliationSnapshot $snapshot): StreamedResponse
    {
        $filename = 'reconciliation-snapshot-'.$snapshot->id.'-'.$snapshot->as_of->format('Y-m-d-His').'.pdf';

        return response()->streamDownload(
            function () use ($snapshot): void {
                @set_time_limit(0);

                echo Pdf::loadView('pdf.reconciliation-snapshot', [
                    'snapshot' => $snapshot,
                ])
                    ->setPaper('a4', 'portrait')
                    ->output();
            },
            $filename,
            ['Content-Type' => 'application/pdf'],
        );
    }
}
