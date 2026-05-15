<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->foreignId('duplicate_of_id')->nullable()->after('hash')
                ->constrained('bank_transactions')->nullOnDelete();
        });

        DB::statement("ALTER TABLE bank_transactions MODIFY COLUMN status ENUM('imported', 'mirrored', 'posted', 'ignored', 'duplicate') DEFAULT 'imported'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE bank_transactions MODIFY COLUMN status ENUM('imported', 'mirrored', 'posted', 'ignored') DEFAULT 'imported'");

        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->dropForeign(['duplicate_of_id']);
            $table->dropColumn('duplicate_of_id');
        });
    }
};
