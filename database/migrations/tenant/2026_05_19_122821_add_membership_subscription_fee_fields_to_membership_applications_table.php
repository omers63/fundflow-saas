<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membership_applications', function (Blueprint $table) {
            $table->decimal('membership_fee_required_amount', 12, 2)
                ->nullable()
                ->after('membership_fee_transfer_reference');
            $table->string('membership_fee_receipt_path')
                ->nullable()
                ->after('membership_fee_required_amount');
        });
    }

    public function down(): void
    {
        Schema::table('membership_applications', function (Blueprint $table) {
            $table->dropColumn([
                'membership_fee_required_amount',
                'membership_fee_receipt_path',
            ]);
        });
    }
};
