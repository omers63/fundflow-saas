<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create(config('comments.table_names.subscriptions', 'comment_subscriptions'), function (Blueprint $table) {
            $table->id();
            if (config('comments.multi_tenancy.enabled', false)) {
                $tenantColumn = config('comments.multi_tenancy.tenant_column', 'tenant_id');
                $tenantType = config('comments.multi_tenancy.tenant_column_type', 'unsignedBigInteger');
                if ($tenantType === 'uuid') {
                    $table->uuid($tenantColumn)->nullable()->index();
                } elseif ($tenantType === 'string') {
                    $table->string($tenantColumn)->nullable()->index();
                } else {
                    $table->unsignedBigInteger($tenantColumn)->nullable()->index();
                }
            }
            $table->morphs('commentable');
            $table->morphs('commenter');
            $table->timestamp('created_at')->nullable();

            $table->unique(['commentable_type', 'commentable_id', 'commenter_type', 'commenter_id'], 'comment_subscriptions_unique');
        });
    }
};
