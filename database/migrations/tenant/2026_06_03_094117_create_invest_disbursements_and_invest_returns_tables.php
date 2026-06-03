<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('invest_disbursements', function (Blueprint $table): void {
            $table->id();
            $table->decimal('amount', 15, 2);
            $table->string('description');
            $table->timestamp('transacted_at');
            $table->foreignId('bank_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('invest_returns', function (Blueprint $table): void {
            $table->id();
            $table->decimal('amount', 15, 2);
            $table->string('description');
            $table->timestamp('transacted_at');
            $table->foreignId('bank_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('bank_transactions', function (Blueprint $table): void {
            $table->foreignId('invest_disbursement_id')
                ->nullable()
                ->after('fee_disbursement_id')
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('invest_return_id')
                ->nullable()
                ->after('invest_disbursement_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('invest_return_id');
            $table->dropConstrainedForeignId('invest_disbursement_id');
        });

        Schema::dropIfExists('invest_returns');
        Schema::dropIfExists('invest_disbursements');
    }
};
