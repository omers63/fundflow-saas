<?php

declare(strict_types=1);

namespace App\Models\Tenant\Relations;

use App\Models\Tenant\Account;
use App\Models\Tenant\BankTransaction;
use App\Services\BankClearingMatchService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * @extends Relation<BankTransaction, Account>
 */
final class MasterAccountPendingClearanceRelation extends Relation
{
    public function __construct(Account $parent)
    {
        parent::__construct(BankTransaction::query(), $parent);
    }

    public function addConstraints(): void
    {
        if (! self::$constraints) {
            return;
        }

        if (! $this->parent->is_master || ! BankClearingMatchService::masterAccountTypeSupportsPendingClearance($this->parent->type)) {
            $this->query->whereRaw('0 = 1');

            return;
        }

        app(BankClearingMatchService::class)
            ->applyPendingOperationalClearanceScopeForMasterAccount($this->query, $this->parent);
    }

    public function addEagerConstraints(array $models): void
    {
        //
    }

    public function initRelation(array $models, $relation): array
    {
        foreach ($models as $model) {
            $model->setRelation($relation, $this->related->newCollection());
        }

        return $models;
    }

    public function match(array $models, EloquentCollection $results, $relation): array
    {
        foreach ($models as $model) {
            $model->setRelation($relation, $this->related->newCollection());
        }

        return $models;
    }

    public function getResults(): EloquentCollection
    {
        return $this->query->get();
    }
}
