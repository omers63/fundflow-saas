<?php

declare(strict_types=1);

use App\Filament\Support\MemberFilamentActions;
use App\Support\BusinessDay;
use Filament\Schemas\Components\StateCasts\DateTimeStateCast;
use Illuminate\Support\Facades\Validator;

/**
 * Non-native Filament DatePickers store {@code Y-m-d H:i:s}. A date-only maxDate is
 * treated as start-of-day, so a value for "today" that includes the current clock time
 * fails {@see before_or_equal}. Withdrawal/freeze/cash-out pickers must allow the whole
 * business day.
 */
test('business day date pickers accept the business day across non-native state shapes', function () {
    $max = MemberFilamentActions::businessDayPickerMaxDate();
    $day = BusinessDay::today()->toDateString();

    $cast = app(DateTimeStateCast::class, [
        'format' => 'Y-m-d',
        'internalFormat' => 'Y-m-d H:i:s',
        'timezone' => config('app.timezone'),
    ]);

    $states = [
        MemberFilamentActions::businessDayPickerDefault(),
        $cast->set($day),
        // Date-only format historically picked up the current clock time on cast.
        $day.' '.now()->format('H:i:s'),
        $day.'T00:00:00.000000Z',
        $day,
    ];

    foreach ($states as $state) {
        $validator = Validator::make(
            ['date' => $state],
            ['date' => 'before_or_equal:'.$max],
        );

        expect($validator->passes())
            ->toBeTrue("Expected business day state [{$state}] to pass maxDate [{$max}]");
    }
});

test('business day date pickers reject the calendar day after the business day', function () {
    $max = MemberFilamentActions::businessDayPickerMaxDate();
    $tomorrow = BusinessDay::today()->addDay()->toDateString();

    $validator = Validator::make(
        ['date' => $tomorrow],
        ['date' => 'before_or_equal:'.$max],
    );

    expect($validator->fails())->toBeTrue();
});

test('withdrawal freeze and cash-out fields use end-of-business-day maxDate', function () {
    expect(MemberFilamentActions::withdrawDateField()->getMaxDate())
        ->toBe(MemberFilamentActions::businessDayPickerMaxDate())
        ->and(MemberFilamentActions::freezeDateField()->getMaxDate())
        ->toBe(MemberFilamentActions::businessDayPickerMaxDate())
        ->and(MemberFilamentActions::cashOutDateField()->getMaxDate())
        ->toBe(MemberFilamentActions::businessDayPickerMaxDate());
});
