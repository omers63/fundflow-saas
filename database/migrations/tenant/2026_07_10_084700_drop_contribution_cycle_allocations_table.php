<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('contribution_cycle_allocations');
    }

    public function down(): void
    {
        Schema::create('contribution_cycle_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_contribution_id')->constrained('contributions')->cascadeOnDelete();
            $table->date('target_period');
            $table->decimal('allocated_amount', 12, 2);
            $table->timestamps();

            $table->index(['member_id', 'target_period'], 'contrib_alloc_member_period_idx');
            $table->unique(
                ['source_contribution_id', 'target_period'],
                'contrib_alloc_source_target_unique',
            );
        });
    }
};
