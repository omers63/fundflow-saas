<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Models\Tenant\ReconciliationException;
use App\Models\Tenant\User;
use App\Support\BusinessDay;
use App\Support\ContributionPolicySettings;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * Admin resolution actions for reconciliation exceptions (§5.10).
 */
class ReconciliationResolutionService
{
    public const ACTION_RESOLVED = 'resolved';

    public const ACTION_ESCALATED = 'escalated';

    public const ACTION_WRITE_OFF = 'write_off';

    public const ACTION_ACCEPT_OVERRIDE = 'accept_override';

    public const ACTION_RECLASSIFIED = 'reclassified';

    public function __construct(
        protected ReconciliationService $reconciliation,
        protected ReconciliationSuspenseService $suspense,
        protected FundAuditLogService $audit,
        protected ReconciliationCorrectionService $corrections,
    ) {}

    public function resolveManually(ReconciliationException $exception, string $notes): ReconciliationException
    {
        $this->assertOpen($exception);

        $exception->update([
            'status' => ReconciliationException::STATUS_RESOLVED,
            'resolution_action' => self::ACTION_RESOLVED,
            'resolution_notes' => $notes,
            'resolved_at' => BusinessDay::now(),
            'assigned_to' => $exception->assigned_to ?? Auth::guard('tenant')->id(),
        ]);

        $this->auditResolution($exception, self::ACTION_RESOLVED, $notes);

        return $exception->fresh();
    }

    public function escalate(ReconciliationException $exception, string $reason): ReconciliationException
    {
        $this->assertOpen($exception);

        $exception->update([
            'status' => ReconciliationException::STATUS_ESCALATED,
            'resolution_action' => self::ACTION_ESCALATED,
            'resolution_notes' => $reason,
            'severity' => $this->escalatedSeverity($exception->severity),
            'sla_deadline' => BusinessDay::now()->endOfDay(),
            'assigned_to' => $exception->assigned_to ?? Auth::guard('tenant')->id(),
        ]);

        $this->auditResolution($exception, self::ACTION_ESCALATED, $reason);

        return $exception->fresh();
    }

    public function writeOff(ReconciliationException $exception, string $reason): ReconciliationException
    {
        $this->assertOpen($exception);

        if (! in_array($exception->severity, ['low', 'medium'], true)) {
            throw new InvalidArgumentException(__('Write-off is only allowed for low or medium severity exceptions.'));
        }

        $delta = (float) ($exception->amount_delta ?? 0);

        if (abs($delta) > ContributionPolicySettings::reconTolerance()) {
            $this->suspense->postRoundingAdjustment($delta);
        }

        $exception->update([
            'status' => ReconciliationException::STATUS_RESOLVED,
            'resolution_action' => self::ACTION_WRITE_OFF,
            'resolution_notes' => $reason,
            'resolved_at' => BusinessDay::now(),
            'auto_resolve_attempted' => true,
            'auto_resolve_reason' => __('Written off to reconciliation suspense'),
            'assigned_to' => $exception->assigned_to ?? Auth::guard('tenant')->id(),
        ]);

        $this->auditResolution($exception, self::ACTION_WRITE_OFF, $reason);

        return $exception->fresh();
    }

    public function acceptOverride(ReconciliationException $exception, string $reason): ReconciliationException
    {
        $this->assertOpen($exception);

        if (! filled($reason)) {
            throw new InvalidArgumentException(__('Supervisor sign-off reason is required.'));
        }

        $exception->update([
            'status' => ReconciliationException::STATUS_RESOLVED,
            'resolution_action' => self::ACTION_ACCEPT_OVERRIDE,
            'resolution_notes' => $reason,
            'resolved_at' => BusinessDay::now(),
            'assigned_to' => $exception->assigned_to ?? Auth::guard('tenant')->id(),
        ]);

        $this->auditResolution($exception, self::ACTION_ACCEPT_OVERRIDE, $reason);

        return $exception->fresh();
    }

    public function reclassify(
        ReconciliationException $exception,
        string $exceptionType,
        string $notes,
    ): ReconciliationException {
        $this->assertOpen($exception);

        $exception->update([
            'exception_type' => $exceptionType,
            'resolution_action' => self::ACTION_RECLASSIFIED,
            'resolution_notes' => $notes,
        ]);

        $this->auditResolution($exception, self::ACTION_RECLASSIFIED, $notes);

        return $exception->fresh();
    }

    public function assignTo(ReconciliationException $exception, ?int $userId): ReconciliationException
    {
        $this->assertOpen($exception);

        if ($userId !== null && ! User::query()->whereKey($userId)->exists()) {
            throw new InvalidArgumentException(__('Selected assignee was not found.'));
        }

        $exception->update(['assigned_to' => $userId]);

        return $exception->fresh();
    }

    public function retryAutoResolve(ReconciliationException $exception): bool
    {
        $this->assertOpen($exception);

        $resolved = $this->reconciliation->attemptAutoResolveForAdmin($exception);

        if ($resolved) {
            $exception->refresh();
        }

        return $resolved;
    }

    /**
     * @return array{reversal_count: int, reversal_transaction_id: ?int}
     */
    public function reverseTransaction(
        ReconciliationException $exception,
        int $transactionId,
        string $reason,
        bool $fullSource = false,
    ): array {
        $this->assertOpen($exception);

        $result = $this->corrections->reverseLinkedTransaction($exception, $transactionId, $reason, $fullSource);

        $exception->update([
            'status' => ReconciliationException::STATUS_RESOLVED,
            'resolved_at' => BusinessDay::now(),
        ]);

        $this->auditResolution($exception, ReconciliationCorrectionService::ACTION_REVERSED, $reason);

        return $result;
    }

    public function postMemberCashCorrection(
        ReconciliationException $exception,
        int $memberId,
        string $direction,
        float $amount,
        string $reason,
    ): void {
        $this->assertOpen($exception);

        $member = Member::query()->findOrFail($memberId);

        $this->corrections->postMemberCashCorrection($exception, $member, $direction, $amount, $reason);

        $exception->update([
            'status' => ReconciliationException::STATUS_RESOLVED,
            'resolved_at' => BusinessDay::now(),
        ]);

        $this->auditResolution($exception, ReconciliationCorrectionService::ACTION_MANUAL_CORRECTION, $reason);
    }

    public function resolveAmbiguousBankMatch(
        ReconciliationException $exception,
        int $importedBankTransactionId,
        int $unclearedBankTransactionId,
        string $notes,
    ): void {
        $this->assertOpen($exception);

        $this->corrections->resolveAmbiguousBankMatch(
            $exception,
            $importedBankTransactionId,
            $unclearedBankTransactionId,
            $notes,
        );

        $this->auditResolution($exception, ReconciliationCorrectionService::ACTION_MANUAL_CORRECTION, $notes);
    }

    public function postEmiOverpaymentRefund(
        ReconciliationException $exception,
        int $loanId,
        float $amount,
        string $reason,
    ): void {
        $this->assertOpen($exception);

        $loan = Loan::query()->findOrFail($loanId);

        $this->corrections->postEmiOverpaymentRefund($exception, $loan, $amount, $reason);

        $exception->update([
            'status' => ReconciliationException::STATUS_RESOLVED,
            'resolved_at' => BusinessDay::now(),
        ]);

        $this->auditResolution($exception, ReconciliationCorrectionService::ACTION_MANUAL_CORRECTION, $reason);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function postCorrection(
        ReconciliationException $exception,
        string $type,
        array $data,
    ): void {
        $this->assertOpen($exception);

        $this->corrections->postCorrection($exception, $type, $data);

        $exception->update([
            'status' => ReconciliationException::STATUS_RESOLVED,
            'resolved_at' => BusinessDay::now(),
        ]);

        $this->auditResolution(
            $exception,
            ReconciliationCorrectionService::ACTION_MANUAL_CORRECTION,
            (string) ($data['reason'] ?? $type),
        );
    }

    protected function assertOpen(ReconciliationException $exception): void
    {
        if (
            $exception->status !== ReconciliationException::STATUS_OPEN
            && $exception->status !== ReconciliationException::STATUS_ESCALATED
        ) {
            throw new InvalidArgumentException(__('This exception is already closed.'));
        }
    }

    protected function escalatedSeverity(string $current): string
    {
        return match ($current) {
            'low' => 'medium',
            'medium' => 'high',
            default => 'critical',
        };
    }

    protected function auditResolution(ReconciliationException $exception, string $action, string $notes): void
    {
        $this->audit->log('RECON_ADMIN_RESOLUTION', 'reconciliation', $exception, null, [
            'action' => $action,
            'exception_code' => $exception->exception_code,
            'notes' => $notes,
            'operator_id' => Auth::guard('tenant')->id(),
        ]);
    }
}
