<?php

declare(strict_types=1);

namespace App\Services\Disbursement;

use App\Contracts\DisbursementFileExporter;
use App\Models\Tenant\DisbursementBatch;
use App\Models\Tenant\DisbursementBatchItem;

/**
 * Minimal pain.001 XML stub for bank file hand-off (not a full ISO schema export).
 */
final class Pain001XmlDisbursementExporter implements DisbursementFileExporter
{
    public function format(): string
    {
        return DisbursementBatch::FORMAT_PAIN001_XML;
    }

    public function export(DisbursementBatch $batch): array
    {
        $batch->loadMissing('items');
        $items = $batch->items->where('status', DisbursementBatchItem::STATUS_INCLUDED);
        $txns = '';

        foreach ($items as $item) {
            $txns .= sprintf(
                '<CdtTrfTxInf><Amt Ccy="SAR">%s</Amt><Cdtr><Nm>%s</Nm></Cdtr><CdtrAcct><Id><IBAN>%s</IBAN></Id></CdtrAcct><EndToEndId>BATCH-%d-ITEM-%d</EndToEndId></CdtTrfTxInf>',
                number_format((float) $item->amount, 2, '.', ''),
                htmlspecialchars($item->payee_name, ENT_XML1),
                htmlspecialchars($item->payee_iban, ENT_XML1),
                $batch->id,
                $item->id,
            );
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Document xmlns="urn:iso:std:iso:20022:tech:xsd:pain.001.001.03">'
            .'<CstmrCdtTrfInitn><GrpHdr><MsgId>BATCH-'.$batch->id.'</MsgId>'
            .'<NbOfTxs>'.$items->count().'</NbOfTxs>'
            .'<CtrlSum>'.number_format((float) $batch->total_amount, 2, '.', '').'</CtrlSum>'
            .'</GrpHdr><PmtInf>'.$txns.'</PmtInf></CstmrCdtTrfInitn></Document>';

        return [
            'contents' => $xml,
            'extension' => 'xml',
            'mime' => 'application/xml',
        ];
    }
}
