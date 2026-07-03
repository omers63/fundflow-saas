<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table): void {
            $table->string('guarantor_name')->nullable()->after('guarantor_member_id');
            $table->string('application_form_path')->nullable()->after('purpose');
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table): void {
            $table->dropColumn(['guarantor_name', 'application_form_path']);
        });
    }
};
