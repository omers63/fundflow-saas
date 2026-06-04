<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_closes', function (Blueprint $table) {
            $table->id();
            $table->string('fiscal_year_label', 16);
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 32)->default('draft');
            $table->json('readiness_report_json')->nullable();
            $table->json('pool_snapshot_json')->nullable();
            $table->unsignedInteger('member_count')->default(0);
            $table->unsignedInteger('active_loan_count')->default(0);
            $table->unsignedInteger('open_arrears_period_count')->default(0);
            $table->json('export_manifest_json')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('purge_started_at')->nullable();
            $table->timestamp('purge_completed_at')->nullable();
            $table->json('purge_summary_json')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('period_end');
            $table->index('fiscal_year_label');
        });

        Schema::create('fiscal_close_member_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_close_id')->constrained('fiscal_closes')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->decimal('cash_balance', 15, 2);
            $table->decimal('fund_balance', 15, 2);
            $table->decimal('opening_cash_before', 15, 2)->nullable();
            $table->decimal('opening_fund_before', 15, 2)->nullable();
            $table->json('contribution_arrears_json');
            $table->json('loans_json');
            $table->json('delinquency_json')->nullable();
            $table->json('eligibility_json')->nullable();
            $table->timestamps();

            $table->unique(['fiscal_close_id', 'member_id']);
        });

        Schema::create('fiscal_close_waivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_close_id')->constrained('fiscal_closes')->cascadeOnDelete();
            $table->string('gate_code', 64);
            $table->text('reason');
            $table->foreignId('waived_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_close_waivers');
        Schema::dropIfExists('fiscal_close_member_snapshots');
        Schema::dropIfExists('fiscal_closes');
    }
};
