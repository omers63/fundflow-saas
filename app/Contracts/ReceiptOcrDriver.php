<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Tenant\FundPosting;
use App\Support\Ocr\ReceiptOcrResult;

interface ReceiptOcrDriver
{
    public function driver(): string;

    public function extract(FundPosting $posting): ReceiptOcrResult;
}
