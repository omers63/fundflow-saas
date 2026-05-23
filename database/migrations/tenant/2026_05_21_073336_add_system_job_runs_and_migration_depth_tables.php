<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_job_runs', function (Blueprint $table) {
            $table->id();
            $table->string('job_key', 64);
            $table->string('command', 128);
            $table->string('trigger', 24)->default('manual');
            $table->string('status', 24)->default('running');
            $table->unsignedSmallInteger('exit_code')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('summary')->nullable();
            $table->longText('output')->nullable();
            $table->timestamps();

            $table->index(['job_key', 'started_at']);
            $table->index(['status', 'started_at']);
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

        Schema::table('migration_cycle_stubs', function (Blueprint $table) {
            if (! Schema::hasColumn('migration_cycle_stubs', 'origin')) {
                $table->string('origin', 32)->default('migration')->after('member_id');
            }
        });

        Schema::table('loans', function (Blueprint $table) {
            if (! Schema::hasColumn('loans', 'lifecycle_stage')) {
                $table->string('lifecycle_stage', 32)->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            if (Schema::hasColumn('loans', 'lifecycle_stage')) {
                $table->dropColumn('lifecycle_stage');
            }
        });

        Schema::table('migration_cycle_stubs', function (Blueprint $table) {
            if (Schema::hasColumn('migration_cycle_stubs', 'origin')) {
                $table->dropColumn('origin');
            }
        });

        Schema::dropIfExists('migration_instalment_schedules');
        Schema::dropIfExists('system_job_runs');
    }
};
