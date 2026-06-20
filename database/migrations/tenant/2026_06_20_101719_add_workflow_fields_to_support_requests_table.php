<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_requests', function (Blueprint $table): void {
            $table->string('status', 32)->default('open')->after('message');
            $table->timestamp('escalated_at')->nullable()->after('status');
            $table->foreignId('assigned_to_user_id')->nullable()->after('escalated_at')->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable()->after('assigned_to_user_id');

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('support_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('assigned_to_user_id');
            $table->dropColumn(['status', 'escalated_at', 'resolved_at']);
        });
    }
};
