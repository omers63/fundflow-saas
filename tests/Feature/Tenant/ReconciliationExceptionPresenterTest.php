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
