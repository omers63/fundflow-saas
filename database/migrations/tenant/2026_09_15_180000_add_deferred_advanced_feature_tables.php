<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('votes', function (Blueprint $table) {
            $table->foreignId('proxy_for_member_id')->nullable()->after('member_id')->constrained('members')->nullOnDelete();
            $table->foreignId('cast_by_member_id')->nullable()->after('proxy_for_member_id')->constrained('members')->nullOnDelete();
            $table->string('proxy_note')->nullable()->after('cast_by_member_id');
        });

        Schema::create('motion_proxy_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('motion_id')->constrained()->cascadeOnDelete();
            $table->foreignId('grantor_member_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('proxy_member_id')->constrained('members')->cascadeOnDelete();
            $table->timestamp('granted_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['motion_id', 'grantor_member_id']);
            $table->index(['motion_id', 'proxy_member_id']);
        });

        Schema::table('disbursement_batches', function (Blueprint $table) {
            $table->timestamp('acked_at')->nullable()->after('generated_at');
            $table->string('ack_file_path')->nullable()->after('acked_at');
            $table->string('ack_sha256', 64)->nullable()->after('ack_file_path');
            $table->unsignedInteger('ack_accepted_count')->default(0)->after('ack_sha256');
            $table->unsignedInteger('ack_rejected_count')->default(0)->after('ack_accepted_count');
        });

        Schema::table('disbursement_batch_items', function (Blueprint $table) {
            $table->string('ack_status', 30)->nullable()->after('status');
            $table->string('ack_reference')->nullable()->after('ack_status');
            $table->text('ack_reason')->nullable()->after('ack_reference');
        });

        Schema::create('webauthn_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('credential_id', 512)->unique();
            $table->text('public_key');
            $table->unsignedBigInteger('sign_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });

        Schema::create('member_risk_outcomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rules_score');
            $table->string('rules_band', 20);
            $table->boolean('defaulted')->default(false);
            $table->string('label_source', 40)->default('manual');
            $table->json('features')->nullable();
            $table->timestamp('labeled_at')->nullable();
            $table->timestamps();

            $table->index(['member_id', 'defaulted']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_risk_outcomes');
        Schema::dropIfExists('webauthn_credentials');

        Schema::table('disbursement_batch_items', function (Blueprint $table) {
            $table->dropColumn(['ack_status', 'ack_reference', 'ack_reason']);
        });

        Schema::table('disbursement_batches', function (Blueprint $table) {
            $table->dropColumn([
                'acked_at',
                'ack_file_path',
                'ack_sha256',
                'ack_accepted_count',
                'ack_rejected_count',
            ]);
        });

        Schema::dropIfExists('motion_proxy_grants');

        Schema::table('votes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('proxy_for_member_id');
            $table->dropConstrainedForeignId('cast_by_member_id');
            $table->dropColumn('proxy_note');
        });
    }
};
