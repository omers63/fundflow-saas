<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->string('status', 30)->default('draft');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('minutes_published_at')->nullable();
            $table->text('minutes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('motions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('type', 40)->default('generic');
            $table->string('status', 30)->default('draft');
            $table->json('payload')->nullable();
            $table->decimal('amount_threshold', 15, 2)->nullable();
            $table->unsignedInteger('yes_count')->default(0);
            $table->unsignedInteger('no_count')->default(0);
            $table->unsignedInteger('abstain_count')->default(0);
            $table->unsignedInteger('eligible_voters')->default(0);
            $table->decimal('quorum_percent', 5, 2)->nullable();
            $table->boolean('quorum_met')->default(false);
            $table->timestamp('voting_opens_at')->nullable();
            $table->timestamp('voting_closes_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'type']);
        });

        Schema::create('votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('motion_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->string('choice', 20);
            $table->timestamp('cast_at');
            $table->timestamps();

            $table->unique(['motion_id', 'member_id']);
        });

        Schema::create('motion_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('motion_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('profit_distributions', function (Blueprint $table) {
            $table->id();
            $table->string('status', 30)->default('draft');
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('amount', 15, 2);
            $table->string('allocation_method', 40)->default('month_end_fund_balance');
            $table->foreignId('motion_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('item_count')->default(0);
            $table->decimal('posted_total', 15, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('profit_distribution_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profit_distribution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->decimal('weight', 18, 6)->default(0);
            $table->decimal('amount', 15, 2);
            $table->string('status', 20)->default('preview');
            $table->timestamps();

            $table->unique(['profit_distribution_id', 'member_id'], 'profit_distribution_items_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profit_distribution_items');
        Schema::dropIfExists('profit_distributions');
        Schema::dropIfExists('motion_attachments');
        Schema::dropIfExists('votes');
        Schema::dropIfExists('motions');
        Schema::dropIfExists('meetings');
    }
};
