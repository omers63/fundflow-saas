<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_import_templates', function (Blueprint $table) {
            $table->id();
            $table->string('bank_name')->nullable()->comment('Optional bank label for duplicate scoping');
            $table->string('name');
            $table->boolean('is_default')->default(false);

            $table->string('delimiter')->default(',');
            $table->string('encoding')->default('UTF-8');
            $table->boolean('has_header')->default(true);
            $table->unsignedTinyInteger('skip_rows')->default(0);

            $table->string('sms_column');
            $table->string('date_column')->nullable();
            $table->string('date_format')->default('Y-m-d H:i:s');

            $table->string('amount_pattern')->nullable();
            $table->string('date_pattern')->nullable();
            $table->string('date_pattern_format')->nullable();
            $table->string('reference_pattern')->nullable();

            $table->json('credit_keywords')->nullable();
            $table->json('debit_keywords')->nullable();
            $table->string('default_transaction_type')->default('credit');

            $table->json('duplicate_match_fields')->nullable();
            $table->unsignedTinyInteger('duplicate_date_tolerance')->default(0);

            $table->string('member_match_pattern')->nullable();
            $table->string('member_match_field')->nullable()->default('member_number');

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_import_templates');
    }
};
