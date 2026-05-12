<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table): void {
            $table->id();
            $table->morphs('commentable');
            $table->morphs('commenter');
            $table->foreignId('parent_id')->nullable()->constrained('comments')->cascadeOnDelete();
            $table->text('body');
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('pinned_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->index(['commentable_type', 'commentable_id', 'parent_id']);
        });

        Schema::create('comment_mentions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('comment_id')->constrained('comments')->cascadeOnDelete();
            $table->morphs('commenter');
            $table->timestamps();
            $table->unique(['comment_id', 'commenter_id', 'commenter_type'], 'comment_mentions_unique');
        });

        Schema::create('comment_reactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('comment_id')->constrained('comments')->cascadeOnDelete();
            $table->morphs('commenter');
            $table->string('reaction');
            $table->timestamps();
            $table->unique(['comment_id', 'commenter_id', 'commenter_type', 'reaction'], 'comment_reactions_unique');
        });

        Schema::create('comment_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->morphs('commentable');
            $table->morphs('commenter');
            $table->timestamp('created_at')->nullable();
            $table->unique(
                ['commentable_type', 'commentable_id', 'commenter_type', 'commenter_id'],
                'comment_subscriptions_unique_tenant'
            );
        });

        Schema::create('comment_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('comment_id')->constrained('comments')->cascadeOnDelete();
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->string('disk');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comment_attachments');
        Schema::dropIfExists('comment_subscriptions');
        Schema::dropIfExists('comment_reactions');
        Schema::dropIfExists('comment_mentions');
        Schema::dropIfExists('comments');
    }
};
