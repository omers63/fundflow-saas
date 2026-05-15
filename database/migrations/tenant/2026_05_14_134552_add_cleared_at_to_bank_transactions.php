<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('bank_transactions', 'is_cleared')) {
            Schema::table('bank_transactions', function (Blueprint $table) {
                $table->boolean('is_cleared')->default(false)->after('raw_data');
            });
        }

        if (! Schema::hasColumn('bank_transactions', 'cleared_at')) {
            Schema::table('bank_transactions', function (Blueprint $table) {
                $table->timestamp('cleared_at')->nullable()->after('is_cleared');
            });
        }

        if (! Schema::hasColumn('bank_transactions', 'fund_posting_id')) {
            Schema::table('bank_transactions', function (Blueprint $table) {
                $table->foreignId('fund_posting_id')->nullable()->after('cleared_at')->constrained()->nullOnDelete();
                $table->index('is_cleared');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->dropForeign(['fund_posting_id']);
            $table->dropColumn(['is_cleared', 'cleared_at', 'fund_posting_id']);
        });
    }
};
