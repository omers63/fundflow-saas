<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contributions', function (Blueprint $table) {
            $table->string('payment_method', 50)->default('cash_account')->after('amount');
            $table->string('reference_number')->nullable()->after('payment_method');
            $table->text('notes')->nullable()->after('reference_number');
            $table->boolean('is_late')->default(false)->after('notes');
            $table->decimal('late_fee_amount', 15, 2)->nullable()->after('is_late');
            $table->timestamp('paid_at')->nullable()->after('posted_at');
            $table->softDeletes();
        });

        Schema::create('dependent_cash_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_member_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('dependent_member_id')->constrained('members')->cascadeOnDelete();
            $table->unsignedTinyInteger('allocation_month');
            $table->unsignedSmallInteger('allocation_year');
            $table->decimal('amount', 15, 2);
            $table->timestamps();

            $table->unique(
                ['dependent_member_id', 'allocation_month', 'allocation_year'],
                'dependent_allocations_period_unique',
            );
        });

        Schema::create('monthly_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->string('period', 7);
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->decimal('total_contributions', 15, 2)->default(0);
            $table->decimal('total_repayments', 15, 2)->default(0);
            $table->decimal('closing_balance', 15, 2)->default(0);
            $table->timestamp('generated_at')->nullable();
            $table->json('details')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['member_id', 'period']);
            $table->index('period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_statements');
        Schema::dropIfExists('dependent_cash_allocations');

        Schema::table('contributions', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn([
                'payment_method',
                'reference_number',
                'notes',
                'is_late',
                'late_fee_amount',
                'paid_at',
            ]);
        });
    }
};
