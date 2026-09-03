<?php

declare(strict_types=1);

use App\Filament\Tenant\Resources\BankAccounts\BankAccountsResource;
use App\Filament\Tenant\Support\BankClearingTabRegistry;
use App\Models\Tenant\BankClearanceMatchGroup;
use App\Models\Tenant\BankStatement;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Member;
use App\Models\Tenant\ReconciliationException;
use App\Support\Reconciliation\ReconciliationExceptionPresenter;
use App\Support\Reconciliation\ReconciliationSnapshotPresenter;
use Filament\Facades\Filament;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function (): void {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');
});

test('reconciliation exception presenter maps known codes to readable titles', function (): void {
    $exception = new ReconciliationException([
        'exception_code' => 'RECON_AMBIGUOUS_MATCH',
        'domain' => 'bank_clearing',
        'severity' => 'high',
        'status' => ReconciliationException::STATUS_OPEN,
        'affected_entities' => [
            'imported_bank_transaction_id' => 42,
            'candidate_ids' => [10, 11],
        ],
    ]);

    expect(ReconciliationExceptionPresenter::title($exception))->toBe(__('Ambiguous bank match'))
        ->and(ReconciliationExceptionPresenter::domainLabel('bank_clearing'))->toBe(__('Bank clearing'))
        ->and(ReconciliationExceptionPresenter::isBankClearingRelated($exception))->toBeTrue()
        ->and(ReconciliationExceptionPresenter::contextItems($exception))->not->toBeEmpty();
});

test('reconciliation exception presenter includes member link when member id is present', function (): void {
    $member = Member::factory()->create();

    $exception = new ReconciliationException([
        'exception_code' => 'MEMBER_CASH_DRIFT',
        'domain' => 'master_account',
        'severity' => 'medium',
        'status' => ReconciliationException::STATUS_OPEN,
        'affected_entities' => [
            'member_id' => $member->id,
        ],
    ]);

    $items = ReconciliationExceptionPresenter::contextItems($exception);

    expect(collect($items)->firstWhere('label', __('Member'))['value'] ?? null)->toBe($member->name)
        ->and(collect($items)->firstWhere('label', __('Member'))['url'] ?? null)->not->toBeNull();
});

test('reconciliation exception presenter renders member drift diagnostics html', function (): void {
    $member = Member::factory()->create();

    $exception = new ReconciliationException([
        'exception_code' => 'MEMBER_CASH_DRIFT',
        'domain' => 'master_account',
        'severity' => 'medium',
        'status' => ReconciliationException::STATUS_OPEN,
        'affected_entities' => [
            'member_id' => $member->id,
        ],
    ]);

    expect(ReconciliationExceptionPresenter::hasMemberDriftDiagnostics($exception))->toBeTrue();

    $html = ReconciliationExceptionPresenter::memberDriftDiagnosticsHtml($exception);

    expect($html)->not->toBeNull()
        ->and((string) $html)->toContain('ff-member-drift-diagnostics')
        ->and((string) $html)->toContain(__('Suggested correction'));
});

test('reconciliation exception presenter recommends actionable fix buttons for bank clearing issues', function (): void {
    $exception = new ReconciliationException([
        'exception_code' => 'RECON_AMBIGUOUS_MATCH',
        'domain' => 'bank_clearing',
        'severity' => 'high',
        'status' => ReconciliationException::STATUS_OPEN,
        'affected_entities' => [
            'imported_bank_transaction_id' => 42,
            'candidate_ids' => [10, 11],
        ],
    ]);

    $actions = ReconciliationExceptionPresenter::recommendedFixActions($exception);

    expect($actions)->not->toBeEmpty()
        ->and(collect($actions)->pluck('name')->filter()->all())->toContain('resolveAmbiguousBankMatch')
        ->and(collect($actions)->firstWhere('type', 'link')['url'] ?? null)->not->toBeNull();
});

test('reconciliation exception bank line context links into bank clearing work queue with line search', function (): void {
    $exception = new ReconciliationException([
        'exception_code' => 'RECON_AMBIGUOUS_MATCH',
        'domain' => 'bank_clearing',
        'severity' => 'high',
        'status' => ReconciliationException::STATUS_OPEN,
        'affected_entities' => [
            'imported_bank_transaction_id' => 42,
            'uncleared_bank_transaction_id' => 17,
            'candidate_ids' => [10, 11],
        ],
    ]);

    $items = collect(ReconciliationExceptionPresenter::contextItems($exception))
        ->where('label', __('Bank line'));

    $importedUrl = $items->firstWhere('value', '#42')['url'] ?? null;
    $unclearedUrl = $items->firstWhere('value', '#17')['url'] ?? null;

    expect($importedUrl)->not->toBeNull()
        ->and($importedUrl)->toContain('tableSearch=42')
        ->and($importedUrl)->toContain('queueFilter='.BankClearingTabRegistry::FILTER_BANK_FILE)
        ->and($unclearedUrl)->not->toBeNull()
        ->and($unclearedUrl)->toContain('tableSearch=17')
        ->and($unclearedUrl)->toContain('queueFilter='.BankClearingTabRegistry::FILTER_OPERATIONS);
});

test('recon snapshot bank line link targets work queue with table search', function (): void {
    $url = BankAccountsResource::workQueueUrlForBankLine(190, BankClearingTabRegistry::FILTER_OPERATIONS);
    $html = (string) ReconciliationSnapshotPresenter::bankLineLink(190);

    expect($url)->toContain('tableSearch=190')
        ->and($url)->toContain('queueFilter='.BankClearingTabRegistry::FILTER_OPERATIONS)
        ->and($html)->toContain('tableSearch=190')
        ->and($html)->toContain('#190');
});

test('reconciliation exception presenter includes bank clearance group context', function (): void {
    $group = BankClearanceMatchGroup::query()->create(['cleared_at' => now()]);

    $statement = BankStatement::create([
        'filename' => 'presenter-group.csv',
        'bank_name' => 'Test Bank',
        'status' => 'completed',
        'total_rows' => 2,
        'imported_rows' => 2,
        'duplicate_rows' => 0,
    ]);

    $importedA = BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => '2026-06-22',
        'description' => 'Split A',
        'amount' => 600,
        'status' => 'posted',
        'hash' => md5('presenter-group-a'),
        'is_cleared' => true,
        'bank_clearance_match_group_id' => $group->id,
    ]);

    BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => '2026-06-22',
        'description' => 'Split B',
        'amount' => 400,
        'status' => 'posted',
        'hash' => md5('presenter-group-b'),
        'is_cleared' => true,
        'bank_clearance_match_group_id' => $group->id,
    ]);

    $exception = new ReconciliationException([
        'exception_code' => 'AMOUNT_MISMATCH',
        'domain' => 'bank_clearing',
        'severity' => 'high',
        'status' => ReconciliationException::STATUS_OPEN,
        'affected_entities' => [
            'imported_bank_transaction_id' => $importedA->id,
        ],
    ]);

    $items = collect(ReconciliationExceptionPresenter::contextItems($exception));

    expect($items->firstWhere('label', __('Match group'))['value'] ?? null)
        ->toBe('2 linked bank rows')
        ->and($items->firstWhere('label', __('Linked operations')))->toBeNull();
});
