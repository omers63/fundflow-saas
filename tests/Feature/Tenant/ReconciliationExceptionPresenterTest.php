<?php

declare(strict_types=1);

use App\Models\Tenant\Member;
use App\Models\Tenant\ReconciliationException;
use App\Support\Reconciliation\ReconciliationExceptionPresenter;
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
