<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_guarantor_replacement_requests', function (Blueprint $table): void {
            $table->dropForeign('lg_repl_prop_guarantor_fk');
        });

        DB::statement('ALTER TABLE `loan_guarantor_replacement_requests` MODIFY `proposed_guarantor_member_id` BIGINT UNSIGNED NULL');

        Schema::table('loan_guarantor_replacement_requests', function (Blueprint $table): void {
            $table->string('proposed_guarantor_name')->nullable()->after('proposed_guarantor_member_id');
            $table->foreign('proposed_guarantor_member_id', 'lg_repl_prop_guarantor_fk')
                ->references('id')->on('members')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('loan_guarantor_replacement_requests', function (Blueprint $table): void {
            $table->dropForeign('lg_repl_prop_guarantor_fk');
            $table->dropColumn('proposed_guarantor_name');
        });

        DB::statement('ALTER TABLE `loan_guarantor_replacement_requests` MODIFY `proposed_guarantor_member_id` BIGINT UNSIGNED NOT NULL');

        Schema::table('loan_guarantor_replacement_requests', function (Blueprint $table): void {
            $table->foreign('proposed_guarantor_member_id', 'lg_repl_prop_guarantor_fk')
                ->references('id')->on('members')->cascadeOnDelete();
        });
    }
};
