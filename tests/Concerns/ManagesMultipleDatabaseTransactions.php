<?php

namespace Tests\Concerns;

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\DatabaseTransactionsManager;

trait ManagesMultipleDatabaseTransactions
{
    public function beginDatabaseTransaction(): void
    {
        if (method_exists($this, 'initializeTenancy')) {
            $this->initializeTenancy();
        }

        $database = $this->app->make('db');
        $connections = $this->connectionsToTransact();

        $transactionsManager = new DatabaseTransactionsManager($connections);
        $this->app->instance('db.transactions', $transactionsManager);

        foreach ($connections as $name) {
            $this->beginTransactionForConnection($database, $name, $transactionsManager);
        }

        $this->beforeApplicationDestroyed(function () use ($database): void {
            $this->rollbackDatabaseTransactions($database);
        });
    }

    protected function beginTransactionForConnection(
        DatabaseManager $database,
        string $name,
        DatabaseTransactionsManager $transactionsManager
    ): void {
        $connection = $database->connection($name);
        $connection->setTransactionManager($transactionsManager);

        $dispatcher = $connection->getEventDispatcher();

        $connection->unsetEventDispatcher();
        $connection->beginTransaction();
        $connection->setEventDispatcher($dispatcher);
    }

    protected function rollbackDatabaseTransactions(DatabaseManager $database): void
    {
        foreach ($this->connectionsToTransact() as $name) {
            $connection = $database->connection($name);
            $dispatcher = $connection->getEventDispatcher();

            $connection->unsetEventDispatcher();

            $connection->rollBack();
            $connection->setEventDispatcher($dispatcher);
            $connection->disconnect();
        }
    }
}
