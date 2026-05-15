<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('type', ['cash', 'fund']);
            $table->string('name');
            $table->decimal('balance', 15, 2)->default(0);
            $table->boolean('is_master')->default(false);
            $table->timestamps();

            $table->index(['member_id', 'type']);
            $table->index('is_master');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
