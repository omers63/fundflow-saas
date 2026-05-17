<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('loan_tiers')) {
            Schema::create('loan_tiers', function (Blueprint $table) {
                $table->id();
                $table->unsignedTinyInteger('tier_number')->unique();
                $table->string('label', 100)->nullable();
                $table->decimal('min_amount', 12, 2);
                $table->decimal('max_amount', 12, 2);
                $table->decimal('min_monthly_installment', 12, 2);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();
            });

            $now = now();
            $rows = [
                [1, 'Tier 1', 1000, 30000, 1000],
                [2, 'Tier 2', 31000, 60000, 1500],
                [3, 'Tier 3', 61000, 90000, 2000],
                [4, 'Tier 4', 91000, 120000, 2500],
                [5, 'Tier 5', 121000, 150000, 3000],
                [6, 'Tier 6', 151000, 180000, 3500],
                [7, 'Tier 7', 181000, 210000, 4000],
                [8, 'Tier 8', 211000, 240000, 4500],
                [9, 'Tier 9', 241000, 270000, 5000],
                [10, 'Tier 10', 271000, 300000, 5500],
            ];
            foreach ($rows as [$num, $label, $min, $max, $installment]) {
                DB::table('loan_tiers')->insert([
                    'tier_number' => $num,
                    'label' => $label,
                    'min_amount' => $min,
                    'max_amount' => $max,
                    'min_monthly_installment' => $installment,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        if (! Schema::hasTable('fund_tiers')) {
            Schema::create('fund_tiers', function (Blueprint $table) {
                $table->id();
                $table->unsignedTinyInteger('tier_number')->unique();
                $table->string('label', 100)->nullable();
                $table->foreignId('loan_tier_id')->nullable()->constrained('loan_tiers')->nullOnDelete();
                $table->decimal('percentage', 5, 2)->default(100);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();
            });

            $now = now();
            DB::table('fund_tiers')->insert([
                'tier_number' => 0,
                'label' => 'Emergency',
                'loan_tier_id' => null,
                'percentage' => 100,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            for ($i = 1; $i <= 10; $i++) {
                DB::table('fund_tiers')->insert([
                    'tier_number' => $i,
                    'label' => "Tier {$i}",
                    'loan_tier_id' => $i,
                    'percentage' => 100,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        Schema::table('loans', function (Blueprint $table) {
            if (! Schema::hasColumn('loans', 'amount_requested')) {
                $table->decimal('amount_requested', 15, 2)->nullable()->after('member_id');
            }
            if (! Schema::hasColumn('loans', 'amount_approved')) {
                $table->decimal('amount_approved', 15, 2)->nullable();
            }
            if (! Schema::hasColumn('loans', 'amount_disbursed')) {
                $table->decimal('amount_disbursed', 15, 2)->default(0);
            }
            if (! Schema::hasColumn('loans', 'loan_tier_id')) {
                $table->foreignId('loan_tier_id')->nullable()->constrained('loan_tiers')->nullOnDelete();
            }
            if (! Schema::hasColumn('loans', 'fund_tier_id')) {
                $table->foreignId('fund_tier_id')->nullable()->constrained('fund_tiers')->nullOnDelete();
            }
            if (! Schema::hasColumn('loans', 'queue_position')) {
                $table->unsignedInteger('queue_position')->nullable();
            }
            if (! Schema::hasColumn('loans', 'member_portion')) {
                $table->decimal('member_portion', 15, 2)->nullable();
            }
            if (! Schema::hasColumn('loans', 'master_portion')) {
                $table->decimal('master_portion', 15, 2)->nullable();
            }
            if (! Schema::hasColumn('loans', 'repaid_to_master')) {
                $table->decimal('repaid_to_master', 15, 2)->default(0);
            }
            if (! Schema::hasColumn('loans', 'installments_count')) {
                $table->unsignedInteger('installments_count')->default(0);
            }
            if (! Schema::hasColumn('loans', 'approved_by_id')) {
                $table->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('loans', 'has_grace_cycle')) {
                $table->boolean('has_grace_cycle')->default(true);
            }
            if (! Schema::hasColumn('loans', 'settled_at')) {
                $table->timestamp('settled_at')->nullable();
            }
            if (! Schema::hasColumn('loans', 'due_date')) {
                $table->date('due_date')->nullable();
            }
            if (! Schema::hasColumn('loans', 'guarantor_member_id')) {
                $table->foreignId('guarantor_member_id')->nullable()->constrained('members')->nullOnDelete();
            }
            if (! Schema::hasColumn('loans', 'guarantor_released_at')) {
                $table->timestamp('guarantor_released_at')->nullable();
            }
            if (! Schema::hasColumn('loans', 'guarantor_liability_transferred_at')) {
                $table->timestamp('guarantor_liability_transferred_at')->nullable();
            }
            if (! Schema::hasColumn('loans', 'witness1_name')) {
                $table->string('witness1_name')->nullable();
            }
            if (! Schema::hasColumn('loans', 'witness1_phone')) {
                $table->string('witness1_phone', 50)->nullable();
            }
            if (! Schema::hasColumn('loans', 'witness2_name')) {
                $table->string('witness2_name')->nullable();
            }
            if (! Schema::hasColumn('loans', 'witness2_phone')) {
                $table->string('witness2_phone', 50)->nullable();
            }
            if (! Schema::hasColumn('loans', 'exempted_month')) {
                $table->unsignedTinyInteger('exempted_month')->nullable();
            }
            if (! Schema::hasColumn('loans', 'exempted_year')) {
                $table->unsignedSmallInteger('exempted_year')->nullable();
            }
            if (! Schema::hasColumn('loans', 'first_repayment_month')) {
                $table->unsignedTinyInteger('first_repayment_month')->nullable();
            }
            if (! Schema::hasColumn('loans', 'first_repayment_year')) {
                $table->unsignedSmallInteger('first_repayment_year')->nullable();
            }
            if (! Schema::hasColumn('loans', 'settlement_threshold')) {
                $table->decimal('settlement_threshold', 8, 4)->nullable();
            }
            if (! Schema::hasColumn('loans', 'late_repayment_count')) {
                $table->unsignedInteger('late_repayment_count')->default(0);
            }
            if (! Schema::hasColumn('loans', 'late_repayment_amount')) {
                $table->decimal('late_repayment_amount', 15, 2)->default(0);
            }
            if (! Schema::hasColumn('loans', 'cancellation_reason')) {
                $table->text('cancellation_reason')->nullable();
            }
            if (! Schema::hasColumn('loans', 'is_emergency')) {
                $table->boolean('is_emergency')->default(false);
            }
            if (! Schema::hasColumn('loans', 'payout_at')) {
                $table->timestamp('payout_at')->nullable();
            }
            if (! Schema::hasColumn('loans', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        if (Schema::hasColumn('loans', 'amount')) {
            DB::table('loans')->whereNull('amount_requested')->update([
                'amount_requested' => DB::raw('amount'),
            ]);
        }

        DB::table('loans')->whereIn('status', ['approved', 'disbursed', 'repaying', 'completed'])
            ->whereNull('amount_approved')
            ->update(['amount_approved' => DB::raw('COALESCE(amount_requested, amount)')]);

        DB::table('loans')->whereIn('status', ['disbursed', 'repaying', 'completed'])
            ->update([
                'amount_disbursed' => DB::raw('COALESCE(amount_approved, amount_requested, amount)'),
                'status' => 'active',
            ]);

        DB::table('loans')->where('status', 'repaying')->update(['payout_at' => DB::raw('disbursed_at')]);

        DB::table('loans')->where('status', 'completed')->update(['status' => 'completed']);

        if (! Schema::hasTable('loan_installments')) {
            Schema::create('loan_installments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
                $table->unsignedInteger('installment_number');
                $table->decimal('amount', 15, 2);
                $table->date('due_date');
                $table->timestamp('paid_at')->nullable();
                $table->string('status', 20)->default('pending');
                $table->boolean('is_late')->default(false);
                $table->decimal('late_fee_amount', 15, 2)->default(0);
                $table->boolean('paid_by_guarantor')->default(false);
                $table->boolean('show_as_loan_repayment_in_collections')->default(false);
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['loan_id', 'installment_number']);
            });
        }

        if (! Schema::hasTable('loan_disbursements')) {
            Schema::create('loan_disbursements', function (Blueprint $table) {
                $table->id();
                $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
                $table->decimal('amount', 15, 2);
                $table->decimal('member_portion', 15, 2)->default(0);
                $table->decimal('master_portion', 15, 2)->default(0);
                $table->timestamp('disbursed_at');
                $table->foreignId('disbursed_by_id')->nullable()->constrained('users')->nullOnDelete();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        Schema::table('accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('accounts', 'loan_id')) {
                $table->foreignId('loan_id')->nullable()->after('member_id')->constrained('loans')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            if (Schema::hasColumn('accounts', 'loan_id')) {
                $table->dropConstrainedForeignId('loan_id');
            }
        });

        Schema::dropIfExists('loan_disbursements');
        Schema::dropIfExists('loan_installments');

        Schema::table('loans', function (Blueprint $table) {
            $columns = [
                'amount_requested',
                'amount_approved',
                'amount_disbursed',
                'loan_tier_id',
                'fund_tier_id',
                'queue_position',
                'member_portion',
                'master_portion',
                'repaid_to_master',
                'installments_count',
                'approved_by_id',
                'has_grace_cycle',
                'settled_at',
                'due_date',
                'guarantor_member_id',
                'guarantor_released_at',
                'guarantor_liability_transferred_at',
                'witness1_name',
                'witness1_phone',
                'witness2_name',
                'witness2_phone',
                'exempted_month',
                'exempted_year',
                'first_repayment_month',
                'first_repayment_year',
                'settlement_threshold',
                'late_repayment_count',
                'late_repayment_amount',
                'cancellation_reason',
                'is_emergency',
                'payout_at',
                'deleted_at',
            ];
            foreach ($columns as $column) {
                if (Schema::hasColumn('loans', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('fund_tiers');
        Schema::dropIfExists('loan_tiers');
    }
};
