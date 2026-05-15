<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membership_applications', function (Blueprint $table) {
            $table->string('gender', 20)->nullable()->after('application_type');
            $table->string('marital_status', 30)->nullable()->after('gender');
            $table->string('national_id', 20)->nullable()->after('marital_status');
            $table->date('date_of_birth')->nullable()->after('national_id');
            $table->text('address')->nullable()->after('date_of_birth');
            $table->string('city', 100)->nullable()->after('address');
            $table->string('home_phone', 30)->nullable()->after('phone');
            $table->string('work_phone', 30)->nullable()->after('home_phone');
            $table->string('mobile_phone', 30)->nullable()->after('work_phone');
            $table->string('occupation', 150)->nullable()->after('mobile_phone');
            $table->string('employer', 150)->nullable()->after('occupation');
            $table->string('work_place', 255)->nullable()->after('employer');
            $table->string('residency_place', 255)->nullable()->after('work_place');
            $table->decimal('monthly_income', 12, 2)->nullable()->after('residency_place');
            $table->string('bank_account_number', 50)->nullable()->after('monthly_income');
            $table->string('iban', 34)->nullable()->after('bank_account_number');
            $table->date('membership_date')->nullable()->after('iban');
            $table->string('next_of_kin_name', 150)->nullable()->after('membership_date');
            $table->string('next_of_kin_phone', 30)->nullable()->after('next_of_kin_name');
            $table->string('application_form_path')->nullable()->after('message');
            $table->decimal('membership_fee_amount', 12, 2)->nullable()->after('application_form_path');
            $table->string('membership_fee_transfer_reference', 120)->nullable()->after('membership_fee_amount');
        });
    }

    public function down(): void
    {
        Schema::table('membership_applications', function (Blueprint $table) {
            $table->dropColumn([
                'gender',
                'marital_status',
                'national_id',
                'date_of_birth',
                'address',
                'city',
                'home_phone',
                'work_phone',
                'mobile_phone',
                'occupation',
                'employer',
                'work_place',
                'residency_place',
                'monthly_income',
                'bank_account_number',
                'iban',
                'membership_date',
                'next_of_kin_name',
                'next_of_kin_phone',
                'application_form_path',
                'membership_fee_amount',
                'membership_fee_transfer_reference',
            ]);
        });
    }
};
