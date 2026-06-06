<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_import_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('bank_name')->nullable();
            $table->foreignId('template_id')->constrained('sms_import_templates');
            $table->foreignId('imported_by')->constrained('users');
            $table->string('filename');
            $table->string('file_path');
            $table->string('status')->default('pending');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->text('notes')->nullable();
            $table->json('error_log')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('sms_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('bank_name')->nullable();
            $table->foreignId('import_session_id')->constrained('sms_import_sessions')->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->date('transaction_date')->nullable();
            $table->decimal('amount', 15, 2)->nullable();
            $table->string('transaction_type')->default('credit');
            $table->string('reference')->nullable();
            $table->text('raw_sms');
            $table->json('raw_data')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_duplicate')->default(false);
            $table->foreignId('duplicate_of_id')->nullable()->constrained('sms_transactions')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['bank_name', 'transaction_date']);
            $table->index(['bank_name', 'reference']);
            $table->index('is_duplicate');
            $table->index('posted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_transactions');
        Schema::dropIfExists('sms_import_sessions');
    }
};
