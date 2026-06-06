<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('reconciliation_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('mode', 32);
            $table->timestampTz('as_of');
            $table->timestampTz('period_start')->nullable();
            $table->timestampTz('period_end')->nullable();
            $table->boolean('is_passing')->default(false);
            $table->unsignedSmallInteger('critical_issues')->default(0);
            $table->unsignedSmallInteger('warnings')->default(0);
            $table->json('summary');
            $table->json('report');
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['mode', 'as_of']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_snapshots');
    }
};
