<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dependent_allocation_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_member_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('dependent_member_id')->constrained('members')->cascadeOnDelete();
            $table->unsignedInteger('old_amount');
            $table->unsignedInteger('new_amount');
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index('parent_member_id');
            $table->index('dependent_member_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dependent_allocation_changes');
    }
};
