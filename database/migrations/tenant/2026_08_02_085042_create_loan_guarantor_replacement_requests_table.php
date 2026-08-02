<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('loan_guarantor_replacement_requests');

        Schema::create('loan_guarantor_replacement_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('loan_id')->constrained('loans')->cascadeOnDelete();
            $table->unsignedBigInteger('outgoing_guarantor_member_id');
            $table->unsignedBigInteger('proposed_guarantor_member_id');
            $table->unsignedBigInteger('borrower_member_id');
            $table->foreignId('proposed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('proposed_by_role', 16);
            $table->string('status', 32)->default('pending_guarantor');
            $table->unsignedBigInteger('freeze_member_request_id')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();

            $table->foreign('outgoing_guarantor_member_id', 'lg_repl_out_guarantor_fk')
                ->references('id')->on('members')->cascadeOnDelete();
            $table->foreign('proposed_guarantor_member_id', 'lg_repl_prop_guarantor_fk')
                ->references('id')->on('members')->cascadeOnDelete();
            $table->foreign('borrower_member_id', 'lg_repl_borrower_fk')
                ->references('id')->on('members')->cascadeOnDelete();
            $table->foreign('freeze_member_request_id', 'lg_repl_freeze_req_fk')
                ->references('id')->on('member_requests')->nullOnDelete();

            $table->index(['outgoing_guarantor_member_id', 'status'], 'lg_repl_out_status_idx');
            $table->index(['proposed_guarantor_member_id', 'status'], 'lg_repl_prop_status_idx');
            $table->index(['loan_id', 'status'], 'lg_repl_loan_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_guarantor_replacement_requests');
    }
};
