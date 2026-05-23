<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membership_applications', function (Blueprint $table): void {
            $table->date('import_arrears_cutoff_date')->nullable()->after('membership_fee_receipt_path');
            $table->decimal('import_cutoff_cash_balance', 12, 2)->default(0)->after('import_arrears_cutoff_date');
            $table->decimal('import_cutoff_fund_balance', 12, 2)->default(0)->after('import_cutoff_cash_balance');
        });
    }

    public function down(): void
    {
        Schema::table('membership_applications', function (Blueprint $table): void {
            $table->dropColumn([
                'import_arrears_cutoff_date',
                'import_cutoff_cash_balance',
                'import_cutoff_fund_balance',
            ]);
        });
    }
};
