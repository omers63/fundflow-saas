<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('loans', 'eligibility_threshold')) {
            return;
        }

        Schema::table('loans', function (Blueprint $table): void {
            $table->decimal('eligibility_threshold', 8, 4)->nullable()->after('settlement_threshold');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('loans', 'eligibility_threshold')) {
            return;
        }

        Schema::table('loans', function (Blueprint $table): void {
            $table->dropColumn('eligibility_threshold');
        });
    }
};
