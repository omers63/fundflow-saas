<?php

declare(strict_types=1);

use App\Filament\Tenant\Resources\BankAccounts\BankAccountsResource;
use App\Filament\Tenant\Resources\BankAccounts\Pages\ListBankAccounts;
use App\Filament\Tenant\Widgets\SmsImportSessionsTableWidget;
use App\Models\Tenant\Account;
use App\Models\Tenant\Member;
use App\Models\Tenant\SmsImportSession;
use App\Models\Tenant\SmsImportTemplate;
use App\Models\Tenant\SmsTransaction;
use App\Models\Tenant\Transaction;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\SmsImportService;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    SmsTransaction::query()->forceDelete();
    SmsImportSession::query()->forceDelete();
    SmsImportTemplate::query()->forceDelete();

    $this->admin = User::create([
        'name' => 'SMS Import Admin',
        'email' => 'sms-import-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->member = Member::factory()->create([
        'member_number' => 'M1001',
        'name' => 'Ahmed Ali',
    ]);

    Account::query()->firstOrCreate(
        ['type' => 'cash', 'is_master' => true],
        ['name' => 'Master Cash', 'balance' => 0],
    );
    Account::query()->firstOrCreate(
        ['type' => 'fund', 'is_master' => true],
        ['name' => 'Master Fund', 'balance' => 0],
    );

    app(AccountingService::class)->createMemberAccounts($this->member);

    $this->template = SmsImportTemplate::create([
        'name' => 'Test SMS Template',
        'bank_name' => 'SNB',
        'sms_column' => 'message',
        'has_header' => true,
        'delimiter' => ',',
        'amount_pattern' => '/SAR\s*(?P<amount>[\d,]+\.?\d*)/i',
        'member_match_pattern' => '/Member[:\s]+(?P<member>M\d+)/',
        'member_match_field' => 'member_number',
        'credit_keywords' => ['credited'],
        'debit_keywords' => ['debited'],
        'is_default' => true,
    ]);
});

test('sms import service parses csv rows and auto matches members', function () {
    Storage::disk('local')->put('sms-imports/test.csv', implode("\n", [
        'message',
        'Member: M1001 credited SAR 250.00 on 01/06/2026 Ref ABC123',
    ]));

    $session = SmsImportSession::create([
        'bank_name' => 'SNB',
        'template_id' => $this->template->id,
        'imported_by' => $this->admin->id,
        'filename' => 'test.csv',
        'file_path' => 'sms-imports/test.csv',
        'status' => 'pending',
    ]);

    $this->actingAs($this->admin, 'tenant');

    $result = app(SmsImportService::class)->import($session);
    $session->refresh();

    expect($session->status)->toBe('completed')
        ->and($session->imported_count)->toBe(1)
        ->and($session->duplicate_count)->toBe(0)
        ->and($result['posted'])->toBe(1);

    $tx = SmsTransaction::query()->where('import_session_id', $session->id)->first();

    expect($tx)->not->toBeNull()
        ->and($tx->member_id)->toBe($this->member->id)
        ->and((float) $tx->amount)->toBe(250.0)
        ->and($tx->transaction_type)->toBe('credit')
        ->and($tx->is_duplicate)->toBeFalse()
        ->and($tx->posted_at)->not->toBeNull();
});

test('sms import service auto posts via importCsv using public disk like bank statements', function () {
    Storage::disk('public')->put('sms-imports/workspace.csv', implode("\n", [
        'message',
        'Member: M1001 credited SAR 75.00 on 03/06/2026 Ref PUB1',
    ]));

    $this->actingAs($this->admin, 'tenant');

    $file = new UploadedFile(
        Storage::disk('public')->path('sms-imports/workspace.csv'),
        'workspace.csv',
    );

    $result = app(SmsImportService::class)->importCsv(
        file: $file,
        relativeStoragePath: 'sms-imports/workspace.csv',
        importedBy: $this->admin->id,
        bankName: 'SNB',
        templateId: $this->template->id,
    );

    expect($result['imported'])->toBe(1)
        ->and($result['posted'])->toBe(1)
        ->and($result['session']->status)->toBe('completed');

    $tx = SmsTransaction::query()->where('import_session_id', $result['session']->id)->first();

    expect($tx?->posted_at)->not->toBeNull();
});

test('sms import service flags duplicate transactions within date tolerance', function () {
    Storage::disk('local')->put('sms-imports/dup.csv', implode("\n", [
        'message',
        'Member: M1001 credited SAR 100.00 on 02/06/2026 Ref DUP1',
        'Member: M1001 credited SAR 100.00 on 02/06/2026 Ref DUP1',
    ]));

    $session = SmsImportSession::create([
        'bank_name' => 'SNB',
        'template_id' => $this->template->id,
        'imported_by' => $this->admin->id,
        'filename' => 'dup.csv',
        'file_path' => 'sms-imports/dup.csv',
        'status' => 'pending',
    ]);

    app(SmsImportService::class)->import($session);
    $session->refresh();

    expect($session->imported_count)->toBe(1)
        ->and($session->duplicate_count)->toBe(1);

    expect(SmsTransaction::query()->where('import_session_id', $session->id)->where('is_duplicate', true)->count())->toBe(1);
});

test('posting sms transaction credits member cash with master mirror', function () {
    $tx = SmsTransaction::create([
        'bank_name' => 'SNB',
        'import_session_id' => SmsImportSession::create([
            'bank_name' => 'SNB',
            'template_id' => $this->template->id,
            'imported_by' => $this->admin->id,
            'filename' => 'manual.csv',
            'file_path' => 'sms-imports/manual.csv',
            'status' => 'completed',
        ])->id,
        'transaction_date' => now()->toDateString(),
        'amount' => 150,
        'transaction_type' => 'credit',
        'raw_sms' => 'Test credit SMS',
    ]);

    $this->actingAs($this->admin, 'tenant');

    app(AccountingService::class)->postSmsTransactionToCash($tx, $this->member);
    $tx->refresh();

    expect($tx->posted_at)->not->toBeNull()
        ->and($tx->member_id)->toBe($this->member->id);

    $ledgerCredit = Transaction::query()
        ->where('reference_type', SmsTransaction::class)
        ->where('reference_id', $tx->id)
        ->where('type', 'credit')
        ->where('amount', 150)
        ->first();

    expect($ledgerCredit)->not->toBeNull();
});

test('bank accounts list page exposes sms workspace channel', function () {
    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($this->admin, 'tenant')
        ->test(ListBankAccounts::class, ['channel' => 'sms', 'smsSubTab' => 'transactions'])
        ->assertSuccessful()
        ->assertSet('channel', 'sms')
        ->assertSet('smsSubTab', 'transactions')
        ->assertSee(__('SMS'))
        ->assertSee(__('Transactions'));
});

test('bank accounts sms history tab exposes table import action', function () {
    Filament::setCurrentPanel('tenant');

    $component = Livewire::actingAs($this->admin, 'tenant')
        ->test(SmsImportSessionsTableWidget::class)
        ->assertSuccessful();

    $headerNames = collect($component->instance()->getTable()->getHeaderActions())
        ->map(fn ($action) => $action->getName())
        ->all();

    expect($headerNames)->toContain('importSms');
});

test('switching from sms to bank channel shows bank statement tabs', function () {
    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($this->admin, 'tenant')
        ->test(ListBankAccounts::class, ['channel' => 'sms'])
        ->call('setChannel', 'bank')
        ->assertSet('channel', 'bank')
        ->assertSee(__('Statement lines'))
        ->assertSee(__('Pending bank match'))
        ->assertSee(__('Master bank ledger'))
        ->assertSee(__('Statements'));
});

test('bank accounts sms channel resolves tab as sms', function () {
    request()->merge(['channel' => 'sms']);

    expect(BankAccountsResource::resolveChannel())->toBe('sms')
        ->and(BankAccountsResource::resolveListBankAccountsTab())->toBe('sms');
});

test('bank accounts list url includes sms channel parameters', function () {
    Filament::setCurrentPanel('tenant');

    $url = BankAccountsResource::listUrl(channel: 'sms', smsSubTab: 'history');

    expect($url)->toContain('channel=sms')
        ->and($url)->toContain('smsSubTab=history');
});
