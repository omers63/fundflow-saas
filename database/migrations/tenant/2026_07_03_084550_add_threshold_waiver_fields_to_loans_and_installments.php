<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table): void {
            $table->timestamp('threshold_waived_at')->nullable()->after('settled_at');
            $table->text('threshold_waiver_reason')->nullable()->after('threshold_waived_at');
            $table->foreignId('threshold_waived_by_id')->nullable()->after('threshold_waiver_reason')->constrained('users')->nullOnDelete();
        });

        Schema::table('loan_installments', function (Blueprint $table): void {
            $table->timestamp('waived_at')->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('loan_installments', function (Blueprint $table): void {
            $table->dropColumn('waived_at');
        });

        Schema::table('loans', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('threshold_waived_by_id');
            $table->dropColumn(['threshold_waived_at', 'threshold_waiver_reason']);
        });
    }
};
