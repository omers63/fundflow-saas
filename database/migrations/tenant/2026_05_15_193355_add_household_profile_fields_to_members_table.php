<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->string('household_email')->nullable()->after('email');
            $table->boolean('is_separated')->default(false)->after('household_email');
            $table->boolean('direct_login_enabled')->default(false)->after('is_separated');
            $table->string('portal_pin')->nullable()->after('direct_login_enabled');

            $table->index('household_email');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropIndex(['household_email']);
            $table->dropColumn([
                'household_email',
                'is_separated',
                'direct_login_enabled',
                'portal_pin',
            ]);
        });
    }
};
