<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disbursement_batches', function (Blueprint $table) {
            $table->id();
            $table->string('status', 30)->default('draft');
            $table->string('bank_format', 40)->default('al_rajhi_csv');
            $table->unsignedInteger('item_count')->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('file_disk_path')->nullable();
            $table->string('file_sha256', 64)->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('disbursement_batch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('disbursement_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outbound_payment_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('payee_name');
            $table->string('payee_iban', 34);
            $table->string('status', 20)->default('included');
            $table->timestamps();

            $table->unique(['disbursement_batch_id', 'outbound_payment_id'], 'disbursement_batch_items_unique');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disbursement_batch_items');
        Schema::dropIfExists('disbursement_batches');
    }
};
