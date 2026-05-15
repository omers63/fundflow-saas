<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_templates', function (Blueprint $table) {
            $table->string('encoding')->default('UTF-8')->after('name');
            $table->string('amount_column')->nullable()->default('2')->change();
            $table->string('amount_mode')->default('single')->after('amount_column');
            $table->string('credit_column')->nullable()->after('amount_mode');
            $table->string('debit_column')->nullable()->after('credit_column');
            $table->json('extra_columns')->nullable()->after('debit_column');
            $table->json('duplicate_fields')->nullable()->after('extra_columns');
            $table->unsignedInteger('duplicate_date_tolerance')->default(0)->after('duplicate_fields');
        });

        if (Schema::hasColumn('bank_templates', 'description_column')) {
            $templates = DB::table('bank_templates')->get();
            foreach ($templates as $t) {
                $extras = [];
                if ($t->description_column) {
                    $extras[] = ['key' => 'description', 'column' => $t->description_column];
                }
                if ($t->reference_column) {
                    $extras[] = ['key' => 'reference', 'column' => $t->reference_column];
                }
                DB::table('bank_templates')->where('id', $t->id)->update([
                    'extra_columns' => json_encode($extras),
                    'duplicate_fields' => json_encode(['date', 'amount', 'description', 'reference']),
                ]);
            }

            Schema::table('bank_templates', function (Blueprint $table) {
                $table->dropColumn(['description_column', 'reference_column']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('bank_templates', function (Blueprint $table) {
            $table->string('description_column')->default('1')->after('date_column');
            $table->string('reference_column')->nullable()->after('amount_column');
        });

        Schema::table('bank_templates', function (Blueprint $table) {
            $table->dropColumn([
                'encoding',
                'amount_mode',
                'credit_column',
                'debit_column',
                'extra_columns',
                'duplicate_fields',
                'duplicate_date_tolerance',
            ]);
        });
    }
};
