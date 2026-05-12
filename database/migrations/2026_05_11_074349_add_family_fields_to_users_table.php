<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('family_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->foreignId('member_id')->nullable()->after('family_id')->constrained()->nullOnDelete();
            $table->string('role')->default('member')->after('password');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('member_id');
            $table->dropConstrainedForeignId('family_id');
            $table->dropColumn('role');
        });
    }
};
