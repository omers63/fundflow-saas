<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fund_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 64);
            $table->string('domain', 32)->nullable();
            $table->nullableMorphs('subject');
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['event_type', 'occurred_at']);
            $table->index('member_id');
        });

        Schema::create('reconciliation_exceptions', function (Blueprint $table) {
            $table->id();
            $table->string('exception_code', 64);
            $table->string('domain', 32);
            $table->string('severity', 16)->default('medium');
            $table->decimal('amount_delta', 14, 2)->nullable();
            $table->json('affected_entities')->nullable();
            $table->boolean('auto_resolve_attempted')->default(false);
            $table->text('auto_resolve_reason')->nullable();
            $table->string('status', 24)->default('open');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('sla_deadline')->nullable();
            $table->timestamp('raised_at');
            $table->timestamps();

            $table->index(['status', 'severity']);
            $table->index(['domain', 'exception_code']);
        });

        Schema::create('migration_cycle_stubs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->date('cycle_date');
            $table->decimal('amount_due', 12, 2)->default(0);
            $table->string('status', 32)->default('unresolved');
            $table->string('classification', 32)->nullable();
            $table->string('resolution_method', 32)->nullable();
            $table->boolean('late_fee_exempt')->default(true);
            $table->text('notes')->nullable();
            $table->timestamp('classified_at')->nullable();
            $table->foreignId('classified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['member_id', 'cycle_date']);
            $table->index(['member_id', 'status']);
        });

        Schema::create('loan_eligibility_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->string('gate', 64);
            $table->text('reason');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['member_id', 'loan_id']);
        });

        Schema::table('members', function (Blueprint $table) {
            $table->date('migration_cutoff_date')->nullable()->after('joined_at');
            $table->string('migration_status', 32)->nullable()->after('migration_cutoff_date');
        });

        Schema::table('loans', function (Blueprint $table) {
            $table->unsignedTinyInteger('grace_cycles')->nullable()->after('has_grace_cycle');
            $table->foreignId('original_borrower_member_id')->nullable()->after('member_id')->constrained('members')->nullOnDelete();
            $table->timestamp('transferred_to_guarantor_at')->nullable()->after('guarantor_liability_transferred_at');
        });

        Schema::table('loan_installments', function (Blueprint $table) {
            $table->string('collection_status', 32)->nullable()->after('status');
            $table->decimal('amount_collected', 12, 2)->default(0)->after('amount');
            $table->timestamp('overdue_since')->nullable()->after('paid_at');
            $table->unsignedTinyInteger('late_fee_tier')->nullable()->after('late_fee_amount');
        });
    }

    public function down(): void
    {
        Schema::table('loan_installments', function (Blueprint $table) {
            $table->dropColumn(['collection_status', 'amount_collected', 'overdue_since', 'late_fee_tier']);
        });

        Schema::table('loans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('original_borrower_member_id');
            $table->dropColumn(['grace_cycles', 'transferred_to_guarantor_at']);
        });

        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['migration_cutoff_date', 'migration_status']);
        });

        Schema::dropIfExists('loan_eligibility_overrides');
        Schema::dropIfExists('migration_cycle_stubs');
        Schema::dropIfExists('reconciliation_exceptions');
        Schema::dropIfExists('fund_audit_logs');
    }
};
