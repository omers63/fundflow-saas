<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reconciliation_exceptions', function (Blueprint $table) {
            if (! Schema::hasColumn('reconciliation_exceptions', 'resolution_action')) {
                $table->string('resolution_action', 32)->nullable()->after('resolution_notes');
            }
        });

        Schema::table('members', function (Blueprint $table) {
            if (! Schema::hasColumn('members', 'partial_clearance_granted_at')) {
                $table->timestamp('partial_clearance_granted_at')->nullable()->after('migration_status');
                $table->text('partial_clearance_notes')->nullable()->after('partial_clearance_granted_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            if (Schema::hasColumn('members', 'partial_clearance_notes')) {
                $table->dropColumn(['partial_clearance_granted_at', 'partial_clearance_notes']);
            }
        });

        Schema::table('reconciliation_exceptions', function (Blueprint $table) {
            if (Schema::hasColumn('reconciliation_exceptions', 'resolution_action')) {
                $table->dropColumn('resolution_action');
            }
        });
    }
};
