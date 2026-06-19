<?php

declare(strict_types=1);

use Illuminate\Validation\Rules\Password;

test('password validation messages are arabic with translated field names', function () {
    app()->setLocale('ar');

    $validator = validator(
        ['data' => ['new_password' => 'short']],
        ['data.new_password' => [Password::min(8)->mixedCase()->numbers()]],
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('data.new_password'))
        ->toContain('كلمة المرور الجديدة')
        ->not->toContain('The ');
});

test('password confirmation mismatch message is arabic', function () {
    app()->setLocale('ar');

    $validator = validator(
        [
            'data' => [
                'new_password' => 'Abcd1234',
                'new_password_confirmation' => 'Wrong1234',
            ],
        ],
        ['data.new_password_confirmation' => 'same:data.new_password'],
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('data.new_password_confirmation'))
        ->toContain('تأكيد كلمة المرور الجديدة')
        ->not->toContain('The ');
});

test('password complexity rule messages are arabic', function () {
    app()->setLocale('ar');

    $validator = validator(
        ['data' => ['new_password' => 'alllowercase1']],
        ['data.new_password' => [Password::min(8)->mixedCase()->numbers()]],
    );

    expect($validator->errors()->first('data.new_password'))
        ->toBe('يجب أن يحتوي حقل كلمة المرور الجديدة على حرف كبير وحرف صغير على الأقل.');
});
