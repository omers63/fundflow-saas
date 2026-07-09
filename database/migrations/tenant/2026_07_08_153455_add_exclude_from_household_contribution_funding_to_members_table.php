<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->boolean('exclude_from_household_contribution_funding')
                ->default(false)
                ->after('monthly_contribution_amount');
        });

        $steps = [500, 1000, 1500, 2000, 2500, 3000];

        if (Schema::hasTable('dependent_allocation_changes')) {
            DB::table('members')
                ->where('monthly_contribution_amount', '<=', 0)
                ->orderBy('id')
                ->get(['id', 'parent_member_id'])
                ->each(function (object $member) use ($steps): void {
                    $restored = DB::table('dependent_allocation_changes')
                        ->where('dependent_member_id', $member->id)
                        ->where('old_amount', '>', 0)
                        ->orderByDesc('id')
                        ->value('old_amount');

                    if ($restored === null || (float) $restored <= 0) {
                        $restored = DB::table('dependent_allocation_changes')
                            ->where('dependent_member_id', $member->id)
                            ->where('new_amount', '>', 0)
                            ->orderByDesc('id')
                            ->value('new_amount');
                    }

                    $amount = (int) round((float) ($restored ?? 500));
                    if (! in_array($amount, $steps, true)) {
                        $amount = 500;
                    }

                    DB::table('members')->where('id', $member->id)->update([
                        'monthly_contribution_amount' => $amount,
                        'exclude_from_household_contribution_funding' => $member->parent_member_id !== null,
                    ]);
                });
        } else {
            DB::table('members')
                ->where('monthly_contribution_amount', '<=', 0)
                ->update([
                    'monthly_contribution_amount' => 500,
                    'exclude_from_household_contribution_funding' => DB::raw('CASE WHEN parent_member_id IS NOT NULL THEN 1 ELSE 0 END'),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->dropColumn('exclude_from_household_contribution_funding');
        });
    }
};
