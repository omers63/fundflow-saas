<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->string('member_name')->nullable();
            $table->string('panel', 16);
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('accessed_at')->useCurrent();
            $table->timestamps();
            $table->softDeletes();

            $table->index('accessed_at');
            $table->index('panel');
            $table->index('member_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_access_logs');
    }
};
