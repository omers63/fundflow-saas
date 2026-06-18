<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            if (!Schema::hasColumn('members', 'late_repayment_count')) {
                $table->unsignedInteger('late_repayment_count')->default(0);
            }
            if (!Schema::hasColumn('members', 'late_repayment_amount')) {
                $table->decimal('late_repayment_amount', 15, 2)->default(0);
            }
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $columns = array_filter([
                Schema::hasColumn('members', 'late_repayment_count') ? 'late_repayment_count' : null,
                Schema::hasColumn('members', 'late_repayment_amount') ? 'late_repayment_amount' : null,
            ]);

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
