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
            if (! Schema::hasColumn('members', 'last_withdrawn_at')) {
                $table->timestamp('last_withdrawn_at')->nullable()->after('status_changed_at');
            }

            if (! Schema::hasColumn('members', 'reinstated_at')) {
                $table->timestamp('reinstated_at')->nullable()->after('last_withdrawn_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            if (Schema::hasColumn('members', 'reinstated_at')) {
                $table->dropColumn('reinstated_at');
            }

            if (Schema::hasColumn('members', 'last_withdrawn_at')) {
                $table->dropColumn('last_withdrawn_at');
            }
        });
    }
};
