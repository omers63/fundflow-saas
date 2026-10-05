<?php

declare(strict_types=1);

use App\Console\Commands\FundAssertMasterInvariantsCommand;
use App\Models\Central\Tenant;
use Illuminate\Support\LazyCollection;
use Symfony\Component\Console\Input\ArrayInput;

it('limits scheduled tenant commands to SCHEDULE_TENANT_IDS when set', function () {
    $tenant = $this->initializeTenancy();
    assert($tenant instanceof Tenant);
    tenancy()->end();

    $previous = $_ENV['SCHEDULE_TENANT_IDS'] ?? $_SERVER['SCHEDULE_TENANT_IDS'] ?? getenv('SCHEDULE_TENANT_IDS');
    $previous = $previous === false ? null : $previous;

    try {
        $command = app(FundAssertMasterInvariantsCommand::class);
        $command->setLaravel(app());
        $command->mergeApplicationDefinition();

        $input = new ArrayInput([], $command->getDefinition());
        $input->bind($command->getDefinition());

        $class = new ReflectionClass($command);
        while ($class !== false && !$class->hasProperty('input')) {
            $class = $class->getParentClass();
        }

        expect($class)->not->toBeFalse();
        $inputProperty = $class->getProperty('input');
        $inputProperty->setAccessible(true);
        $inputProperty->setValue($command, $input);

        $getTenants = new ReflectionMethod($command, 'getTenants');
        $getTenants->setAccessible(true);

        putenv('SCHEDULE_TENANT_IDS=testing');
        $_ENV['SCHEDULE_TENANT_IDS'] = 'testing';
        $_SERVER['SCHEDULE_TENANT_IDS'] = 'testing';

        /** @var LazyCollection $matched */
        $matched = $getTenants->invoke($command);
        expect($matched->pluck('id')->all())->toBe(['testing']);

        putenv('SCHEDULE_TENANT_IDS=no-such-tenant');
        $_ENV['SCHEDULE_TENANT_IDS'] = 'no-such-tenant';
        $_SERVER['SCHEDULE_TENANT_IDS'] = 'no-such-tenant';

        $matched = $getTenants->invoke($command);
        expect($matched->pluck('id')->all())->toBe([]);
    } finally {
        if ($previous === null) {
            putenv('SCHEDULE_TENANT_IDS');
            unset($_ENV['SCHEDULE_TENANT_IDS'], $_SERVER['SCHEDULE_TENANT_IDS']);
        } else {
            putenv('SCHEDULE_TENANT_IDS=' . $previous);
            $_ENV['SCHEDULE_TENANT_IDS'] = $previous;
            $_SERVER['SCHEDULE_TENANT_IDS'] = $previous;
        }

        tenancy()->initialize($tenant);
    }
});
