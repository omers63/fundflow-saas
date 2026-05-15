<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parent_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->string('member_number')->unique();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->decimal('monthly_contribution_amount', 15, 2)->default(0);
            $table->date('joined_at');
            $table->enum('status', ['active', 'suspended', 'withdrawn'])->default('active');
            $table->timestamps();

            $table->index('status');
            $table->index('joined_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};
