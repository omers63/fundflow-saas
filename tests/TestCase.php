<?php

namespace Tests;

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
    }

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'tenant'];
    }
}
