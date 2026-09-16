<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fund_postings', function (Blueprint $table) {
            $table->json('ocr_extraction')->nullable()->after('attachment');
            $table->decimal('ocr_confidence', 5, 2)->nullable()->after('ocr_extraction');
            $table->string('ocr_match_status', 30)->nullable()->after('ocr_confidence');
        });
    }

    public function down(): void
    {
        Schema::table('fund_postings', function (Blueprint $table) {
            $table->dropColumn(['ocr_extraction', 'ocr_confidence', 'ocr_match_status']);
        });
    }
};
