<?php

declare(strict_types=1);

use App\Services\ContributionCycleService;
use Carbon\Carbon;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Carbon::setTestNow(Carbon::parse('2026-02-06'));
    $this->cycles = app(ContributionCycleService::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('february cycle includes due dates from feb 6 through mar 5', function () {
    [$month, $year] = $this->cycles->currentOpenPeriod();

    expect([$month, $year])->toBe([2, 2026])
        ->and($this->cycles->dueDateFallsInCycle('2026-02-06', 2, 2026))->toBeTrue()
        ->and($this->cycles->dueDateFallsInCycle('2026-03-05', 2, 2026))->toBeTrue()
        ->and($this->cycles->dueDateFallsInCycle('2026-03-06', 2, 2026))->toBeFalse()
        ->and($this->cycles->cyclePeriodForDueDate('2026-03-05'))->toBe([2, 2026])
        ->and($this->cycles->cyclePeriodForDueDate('2026-03-06'))->toBe([3, 2026]);
});
