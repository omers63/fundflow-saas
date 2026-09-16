<?php

declare(strict_types=1);

namespace App\Services\Governance;

use App\Models\Tenant\Meeting;
use App\Support\Pdf\DomPdfFactory;
use App\Support\PublicPageSettings;
use Barryvdh\DomPDF\PDF as DomPdfDocument;

final class MeetingMinutesPdfService
{
    public function make(Meeting $meeting): DomPdfDocument
    {
        $meeting->loadMissing(['motions']);

        return DomPdfFactory::loadView('pdf.meeting-minutes', [
            'meeting' => $meeting,
            'fundName' => PublicPageSettings::fundName(tenant('name')),
            'generatedAt' => now(),
        ]);
    }
}
