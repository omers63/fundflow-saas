<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membership_applications', function (Blueprint $table) {
            $table->foreignId('parent_member_id')
                ->nullable()
                ->after('parent_application_id')
                ->constrained('members')
                ->nullOnDelete();
            $table->foreignId('submitted_by_user_id')
                ->nullable()
                ->after('parent_member_id')
                ->constrained('users')
                ->nullOnDelete();

            $table->index('parent_member_id');
            $table->index('submitted_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('membership_applications', function (Blueprint $table) {
            $table->dropForeign(['parent_member_id']);
            $table->dropForeign(['submitted_by_user_id']);
            $table->dropIndex(['parent_member_id']);
            $table->dropIndex(['submitted_by_user_id']);
            $table->dropColumn(['parent_member_id', 'submitted_by_user_id']);
        });
    }
};
