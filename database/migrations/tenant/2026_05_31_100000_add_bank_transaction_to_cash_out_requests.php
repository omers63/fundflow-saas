<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_out_requests', function (Blueprint $table) {
            $table->foreignId('bank_transaction_id')->nullable()->after('reviewed_at')->constrained()->nullOnDelete();
        });

        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->foreignId('cash_out_request_id')->nullable()->after('membership_application_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_out_request_id');
        });

        Schema::table('cash_out_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bank_transaction_id');
        });
    }
};
