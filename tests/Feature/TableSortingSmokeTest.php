<?php

use App\Models\Tenant\User;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Tables\Contracts\HasTable;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

/**
 * Sorting every sortable column on every tenant list/table page must not hit a SQL error
 * (e.g. a virtual column such as "early_settlement" that has no sort query).
 */
it('sorts every sortable column of every mountable tenant table without a SQL error', function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');
    User::query()->delete();
    $admin = User::create([
        'name' => 'Sort Admin',
        'email' => 'sort-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
        'preferred_locale' => 'en',
    ]);
    $this->actingAs($admin, 'tenant');

    $classes = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Filament/Tenant')));
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $rel = str_replace([app_path().'/', '.php', '/'], ['', '', '\\'], $file->getPathname());
        $class = 'App\\'.$rel;
        if (class_exists($class) && is_subclass_of($class, HasTable::class) && is_subclass_of($class, \Livewire\Component::class)) {
            $classes[] = $class;
        }
    }

    $failures = [];
    $checked = 0;
    $skipped = [];

    // URL-driven tabs / segments change which table a page shows, so every variant is swept.
    $pools = [
        'collectionSegment' => ['collect', 'collected', 'arrears'],
        'cycleSegment' => ['collect', 'collected', 'arrears'],
        'portfolioView' => [null, 'eligibility'],
        'ledgerView' => [null, 'arrears', 'late'],
        'disbursementTab' => ['pending', 'partial', 'complete'],
        'jobsTab' => ['status', 'schedule', 'catalog', 'history'],
        'queueTab' => \App\Filament\Tenant\Pages\LoanQueueWorkbenchPage::TABS,
        'queueFilter' => collect([\App\Filament\Tenant\Support\SmsClearingTabRegistry::class, \App\Filament\Tenant\Support\BankClearingTabRegistry::class])
            ->flatMap(fn ($c) => array_filter((new ReflectionClass($c))->getConstants(), fn ($v, $k) => str_starts_with($k, 'FILTER_'), ARRAY_FILTER_USE_BOTH))->unique()->values()->all(),
        'historySection' => collect([\App\Filament\Tenant\Support\SmsClearingTabRegistry::class, \App\Filament\Tenant\Support\BankClearingTabRegistry::class])
            ->flatMap(fn ($c) => array_filter((new ReflectionClass($c))->getConstants(), fn ($v, $k) => str_starts_with($k, 'HISTORY_'), ARRAY_FILTER_USE_BOTH))->unique()->values()->all(),
    ];

    $make = function (string $class, array $props = []) {
        $urlNames = ['activeTab' => 'tab', 'collectionSegment' => 'segment', 'cycleSegment' => 'segment'];
        $query = [];
        foreach ($props as $prop => $value) {
            if ($value !== null) {
                $query[$urlNames[$prop] ?? $prop] = $value;
            }
        }
        request()->query->replace($query);
        request()->request->replace($query);
        request()->merge($query);

        $instance = Livewire::new($class);
        foreach ($props as $prop => $value) {
            $instance->{$prop} = $value;
        }
        if (method_exists($instance, 'mount')) {
            $instance->mount();
        }
        foreach ($props as $prop => $value) {
            $instance->{$prop} = $value;
        }
        if (method_exists($instance, 'bootedInteractsWithTable')) {
            $instance->bootedInteractsWithTable();
        }

        return $instance;
    };

    foreach ($classes as $class) {
        $probe = null;
        try {
            $probe = $make($class);
        } catch (Throwable $e) {
            $skipped[] = class_basename($class);

            continue;
        }

        $variants = [[]];
        if (method_exists($probe, 'getTabs')) {
            foreach (array_keys($probe->getTabs()) as $tab) {
                $variants[] = ['activeTab' => (string) $tab];
            }
        }
        foreach ($pools as $prop => $values) {
            if (property_exists($probe, $prop)) {
                foreach ($values as $value) {
                    $variants[] = [$prop => $value];
                }
            }
        }

        foreach ($variants as $props) {
            try {
                $columns = $make($class, $props)->getTable()->getColumns();
            } catch (Throwable $e) {
                $failures[] = class_basename($class).' '.json_encode($props).' — mount: '.substr($e->getMessage(), 0, 140);

                continue;
            }
            foreach ($columns as $name => $column) {
                if (! $column->isSortable()) {
                    continue;
                }
                foreach (['asc', 'desc'] as $dir) {
                    try {
                        $instance = $make($class, $props);
                        $instance->sortTable($name, $dir);
                        $instance->getTableRecords();
                        $checked++;
                    } catch (Throwable $e) {
                        $failures[] = class_basename($class).' '.json_encode($props).'::'.$name.' '.$dir.' — '.substr($e->getMessage(), 0, 160);
                    }
                }
            }
        }
    }

    fwrite(STDERR, "\nsort checks: {$checked}; skipped (not mountable alone): ".implode(', ', $skipped)."\n");
    expect($failures)->toBe([]);
});
