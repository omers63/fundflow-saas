<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 40);
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('SAR');
            $table->string('status', 20)->default('pending');
            $table->string('provider', 40);
            $table->string('provider_ref')->nullable();
            $table->text('checkout_url')->nullable();
            $table->text('client_secret')->nullable();
            $table->nullableMorphs('payable');
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('bank_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_ref']);
            $table->index(['member_id', 'status']);
            $table->index('purpose');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_payments');
    }
};
