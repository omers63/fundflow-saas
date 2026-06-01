<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('migration_instalment_schedules');
        Schema::dropIfExists('migration_cycle_stubs');

        Schema::table('members', function (Blueprint $table) {
            if (Schema::hasColumn('members', 'partial_clearance_notes')) {
                $table->dropColumn([
                    'migration_cutoff_date',
                    'migration_status',
                    'partial_clearance_granted_at',
                    'partial_clearance_notes',
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            if (! Schema::hasColumn('members', 'migration_cutoff_date')) {
                $table->date('migration_cutoff_date')->nullable()->after('joined_at');
                $table->string('migration_status', 32)->nullable()->after('migration_cutoff_date');
                $table->timestamp('partial_clearance_granted_at')->nullable()->after('migration_status');
                $table->text('partial_clearance_notes')->nullable()->after('partial_clearance_granted_at');
            }
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

        Schema::create('migration_instalment_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->date('cycle_date');
            $table->decimal('amount', 12, 2);
            $table->string('status', 24)->default('pending');
            $table->timestamp('collected_at')->nullable();
            $table->timestamps();

            $table->index(['member_id', 'status']);
        });
    }
};
