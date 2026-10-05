<?php

namespace Tests;

use App\Models\Tenant\Setting;
use App\Support\LegacyImportedLoan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Concerns\InitializesTenancy;
use Tests\Concerns\ManagesMultipleDatabaseTransactions;

abstract class TestCase extends BaseTestCase
{
    use InitializesTenancy;
    use ManagesMultipleDatabaseTransactions;
    use RefreshDatabase {
        ManagesMultipleDatabaseTransactions::beginDatabaseTransaction
            insteadof RefreshDatabase;
    }

    protected function setUp(): void
    {
        parent::setUp();

        Setting::flushMemo();
        LegacyImportedLoan::flushMemo();
    }

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'tenant'];
    }
}
