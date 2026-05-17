<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membership_applications', function (Blueprint $table) {
            $table->string('household_email')->nullable()->after('email');
            $table->foreignId('parent_application_id')
                ->nullable()
                ->after('household_email')
                ->constrained('membership_applications')
                ->nullOnDelete();
            $table->foreignId('member_id')
                ->nullable()
                ->after('parent_application_id')
                ->constrained('members')
                ->nullOnDelete();

            $table->index('household_email');
            $table->index('parent_application_id');
        });
    }

    public function down(): void
    {
        Schema::table('membership_applications', function (Blueprint $table) {
            $table->dropForeign(['parent_application_id']);
            $table->dropForeign(['member_id']);
            $table->dropIndex(['household_email']);
            $table->dropIndex(['parent_application_id']);
            $table->dropColumn(['household_email', 'parent_application_id', 'member_id']);
        });
    }
};
