<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->unsignedSmallInteger('freeze_cycles_requested')->nullable()->after('frozen_at');
            $table->unsignedSmallInteger('freeze_cycles_remaining')->nullable()->after('freeze_cycles_requested');
            $table->unsignedSmallInteger('freeze_emi_cycles_pushed')->nullable()->after('freeze_cycles_remaining');
            $table->timestamp('freeze_plan_ended_at')->nullable()->after('freeze_emi_cycles_pushed');
            $table->string('freeze_household_mode', 32)->nullable()->after('freeze_plan_ended_at');
            $table->foreignId('freeze_temporary_parent_member_id')
                ->nullable()
                ->after('freeze_household_mode')
                ->constrained('members')
                ->nullOnDelete();
            $table->foreignId('freeze_origin_member_id')
                ->nullable()
                ->after('freeze_temporary_parent_member_id')
                ->constrained('members')
                ->nullOnDelete()
                ->comment('When set, this member was cascade-frozen with a parent');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('freeze_temporary_parent_member_id');
            $table->dropConstrainedForeignId('freeze_origin_member_id');
            $table->dropColumn([
                'freeze_cycles_requested',
                'freeze_cycles_remaining',
                'freeze_emi_cycles_pushed',
                'freeze_plan_ended_at',
                'freeze_household_mode',
            ]);
        });
    }
};
