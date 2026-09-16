<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Tenant\DisbursementBatch;
use App\Models\Tenant\Member;
use App\Models\Tenant\Motion;
use App\Services\Disbursement\SarieAckImportService;
use App\Services\Gateway\GatewayPaymentService;
use App\Services\Governance\MotionService;
use App\Services\Savings\MemberSavingsGoalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class WriteApiController extends Controller
{
    public function createGatewayPayment(Request $request, GatewayPaymentService $payments): JsonResponse
    {
        $data = $request->validate([
            'member_id' => ['required', 'integer', 'exists:members,id'],
            'purpose' => ['required', 'string'],
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $member = Member::query()->findOrFail((int) $data['member_id']);

        try {
            $payment = $payments->createIntent(
                $member,
                (string) $data['purpose'],
                (float) $data['amount'],
                expectedOwed: (float) $data['amount'],
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => [
                'id' => $payment->id,
                'status' => $payment->status,
                'provider' => $payment->provider,
                'provider_ref' => $payment->provider_ref,
                'amount' => (float) $payment->amount,
                'purpose' => $payment->purpose,
            ],
        ], 201);
    }

    public function castVote(Request $request, Motion $motion, MotionService $motions): JsonResponse
    {
        $data = $request->validate([
            'member_id' => ['required', 'integer', 'exists:members,id'],
            'choice' => ['required', 'string'],
        ]);

        $member = Member::query()->findOrFail((int) $data['member_id']);

        try {
            $vote = $motions->castVote($motion, $member, (string) $data['choice']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => [
                'id' => $vote->id,
                'motion_id' => $vote->motion_id,
                'member_id' => $vote->member_id,
                'choice' => $vote->choice,
            ],
        ], 201);
    }

    public function createSavingsGoal(Request $request, MemberSavingsGoalService $goals): JsonResponse
    {
        $data = $request->validate([
            'member_id' => ['required', 'integer', 'exists:members,id'],
            'title' => ['required', 'string', 'max:120'],
            'target_amount' => ['required', 'numeric', 'min:1'],
            'target_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $member = Member::query()->findOrFail((int) $data['member_id']);
        $goal = $goals->create($member, $data);

        return response()->json([
            'data' => [
                'id' => $goal->id,
                'member_id' => $goal->member_id,
                'title' => $goal->title,
                'target_amount' => (float) $goal->target_amount,
                'status' => $goal->status,
            ],
        ], 201);
    }

    public function importDisbursementAck(Request $request, DisbursementBatch $batch, SarieAckImportService $acks): JsonResponse
    {
        $data = $request->validate([
            'contents' => ['required', 'string'],
        ]);

        try {
            $result = $acks->import($batch, (string) $data['contents']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $result]);
    }
}
