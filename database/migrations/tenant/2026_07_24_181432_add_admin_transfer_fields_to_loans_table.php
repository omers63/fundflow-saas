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
            $table->foreignId('transferred_from_loan_id')
                ->nullable()
                ->after('original_borrower_member_id')
                ->constrained('loans')
                ->nullOnDelete();
            $table->string('admin_transfer_mode', 32)->nullable()->after('transferred_from_loan_id');
            $table->timestamp('admin_transferred_at')->nullable()->after('admin_transfer_mode');
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('transferred_from_loan_id');
            $table->dropColumn(['admin_transfer_mode', 'admin_transferred_at']);
        });
    }
};
