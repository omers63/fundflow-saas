<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_statements', function (Blueprint $table) {
            $table->foreignId('bank_template_id')
                ->nullable()
                ->after('bank_name')
                ->constrained('bank_templates')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bank_statements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bank_template_id');
        });
    }
};
