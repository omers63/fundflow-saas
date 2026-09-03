<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\SmsTransaction;
use App\Support\EvidenceChannelSettings;
use InvalidArgumentException;

/**
 * Pre-flight analysis and channel switch for bank CSV ↔ SMS evidence paths.
 */
final class EvidenceChannelMigrationService
{
    public function __construct(
        private BankClearingMatchService $bankClearing,
    ) {}

    /**
     * @return array{
     *     current_channel: string,
     *     target_channel: string,
     *     pending_operational_rows: int,
     *     posted_sms_without_ops_link: int,
     *     posted_sms_without_bank_link: int,
     *     open_bank_import_lines: int,
     *     accepted_deposits_uncleared_ops: int,
     *     warnings: list<string>,
     * }
     */
    public function analyze(string $targetChannel): array
    {
        $targetChannel = $this->normalizeChannel($targetChannel);
        $current = EvidenceChannelSettings::channel();

        $pendingOps = (int) $this->bankClearing
            ->applyPendingOperationalClearanceScope(BankTransaction::query())
            ->whereNull('sms_ops_clearance_match_group_id')
            ->count();

        $postedSmsWithoutOps = (int) SmsTransaction::query()
            ->whereNotNull('posted_at')
            ->where('is_duplicate', false)
            ->where('is_ops_cleared', false)
            ->whereNull('sms_ops_clearance_match_group_id')
            ->count();

        $postedSmsWithoutBank = (int) SmsTransaction::query()
            ->whereNotNull('posted_at')
            ->where('is_duplicate', false)
            ->where('is_bank_cleared', false)
            ->whereNull('sms_clearance_match_group_id')
            ->count();

        $openBankImports = (int) $this->bankClearing
            ->applyRealBankStatementLinesScope(BankTransaction::query())
            ->where('status', 'imported')
            ->whereNull('bank_clearance_match_group_id')
            ->whereNull('sms_clearance_match_group_id')
            ->count();

        $acceptedDepositsUnclearedOps = (int) FundPosting::query()
            ->where('status', 'accepted')
            ->whereHas('bankTransaction', fn ($query) => $query
                ->where('is_cleared', false)
                ->whereNull('sms_ops_clearance_match_group_id'))
            ->count();

        $warnings = $this->warnings(
            $current,
            $targetChannel,
            $pendingOps,
            $postedSmsWithoutOps,
            $postedSmsWithoutBank,
            $openBankImports,
            $acceptedDepositsUnclearedOps,
        );

        return [
            'current_channel' => $current,
            'target_channel' => $targetChannel,
            'pending_operational_rows' => $pendingOps,
            'posted_sms_without_ops_link' => $postedSmsWithoutOps,
            'posted_sms_without_bank_link' => $postedSmsWithoutBank,
            'open_bank_import_lines' => $openBankImports,
            'accepted_deposits_uncleared_ops' => $acceptedDepositsUnclearedOps,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function migrate(string $targetChannel, bool $dryRun = true, bool $force = false): array
    {
        $report = $this->analyze($targetChannel);

        if ($report['current_channel'] === $report['target_channel']) {
            throw new InvalidArgumentException(__('Evidence channel is already set to :channel.', [
                'channel' => $report['target_channel'],
            ]));
        }

        if ($report['warnings'] !== [] && ! $force && ! $dryRun) {
            throw new InvalidArgumentException(__('Resolve migration warnings first or pass --force. :warnings', [
                'warnings' => implode(' ', $report['warnings']),
            ]));
        }

        if (! $dryRun) {
            EvidenceChannelSettings::save($report['target_channel']);
        }

        $report['applied'] = ! $dryRun;

        return $report;
    }

    /**
     * @return list<string>
     */
    private function warnings(
        string $current,
        string $target,
        int $pendingOps,
        int $postedSmsWithoutOps,
        int $postedSmsWithoutBank,
        int $openBankImports,
        int $acceptedDepositsUnclearedOps,
    ): array {
        $warnings = [];

        if ($target === EvidenceChannelSettings::CHANNEL_SMS) {
            if ($openBankImports > 0) {
                $warnings[] = __(':count open bank import line(s) will no longer be matchable via CSV.', [
                    'count' => $openBankImports,
                ]);
            }

            if ($postedSmsWithoutBank > 0) {
                $warnings[] = __(':count posted SMS row(s) still lack a bank CSV link.', [
                    'count' => $postedSmsWithoutBank,
                ]);
            }
        }

        if ($target === EvidenceChannelSettings::CHANNEL_BANK_CSV && $current !== EvidenceChannelSettings::CHANNEL_BANK_CSV) {
            if ($pendingOps > 0) {
                $warnings[] = __(':count uncleared operational row(s) still need SMS ops evidence.', [
                    'count' => $pendingOps,
                ]);
            }

            if ($postedSmsWithoutOps > 0) {
                $warnings[] = __(':count posted SMS row(s) still lack an ops link.', [
                    'count' => $postedSmsWithoutOps,
                ]);
            }
        }

        if ($target === EvidenceChannelSettings::CHANNEL_BOTH) {
            if ($acceptedDepositsUnclearedOps > 0) {
                $warnings[] = __(':count accepted deposit(s) still have uncleared ops rows.', [
                    'count' => $acceptedDepositsUnclearedOps,
                ]);
            }
        }

        return $warnings;
    }

    private function normalizeChannel(string $channel): string
    {
        return match ($channel) {
            EvidenceChannelSettings::CHANNEL_BANK_CSV,
            EvidenceChannelSettings::CHANNEL_SMS,
            EvidenceChannelSettings::CHANNEL_BOTH => $channel,
            default => throw new InvalidArgumentException(__('Target channel must be bank_csv, sms, or both.')),
        };
    }
}
