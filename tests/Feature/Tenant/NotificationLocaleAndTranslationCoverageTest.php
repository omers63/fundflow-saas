<?php

declare(strict_types=1);

use App\Filament\Support\RecipientDatabaseNotification;
use App\Http\Middleware\SetApplicationLocale;
use App\Models\Tenant\User;
use App\Support\NotificationTemplateCatalog;
use App\Support\TenantAuthUser;
use App\Support\UserPreferredLocale;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
});

function makeLocaleTestUser(array $attributes = []): User
{
    return User::create(array_merge([
        'name' => 'Locale Admin',
        'email' => 'locale-admin-'.uniqid('', true).'@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ], $attributes));
}

test('tenant guard user is resolved for preferred locale sync', function () {
    $admin = makeLocaleTestUser([
        'preferred_locale' => 'ar',
    ]);

    auth('tenant')->login($admin);

    expect(TenantAuthUser::user()?->is($admin))->toBeTrue()
        ->and(auth()->user())->toBeNull();

    UserPreferredLocale::persist($admin, 'en');

    expect($admin->fresh()->preferred_locale)->toBe('en')
        ->and($admin->fresh()->preferredLocale())->toBe('en');
});

test('set application locale middleware persists switcher session language for tenant admins', function () {
    $admin = makeLocaleTestUser([
        'preferred_locale' => 'ar',
    ]);

    auth('tenant')->login($admin);
    session(['locale' => 'en']);

    $middleware = app(SetApplicationLocale::class);
    $request = Request::create('/admin', 'GET');
    $request->setLaravelSession(app('session.store'));

    $middleware->handle($request, fn (): Response => response('ok'));

    expect(app()->getLocale())->toBe('en')
        ->and($admin->fresh()->preferred_locale)->toBe('en');
});

test('recipient database notifications for jobs use the synced preferred locale', function () {
    app()->setLocale('ar');

    $admin = makeLocaleTestUser([
        'preferred_locale' => 'en',
    ]);

    RecipientDatabaseNotification::sendWithColor(
        $admin,
        fn ($notification) => $notification
            ->title(__('Migration complete'))
            ->body(__('Importing members')),
        'success',
    );

    $stored = $admin->fresh()->notifications()->firstOrFail();

    expect($stored->data['title'] ?? null)->toBe('Migration complete')
        ->and($stored->data['body'] ?? null)->toBe('Importing members');
});

test('notification template catalog ships english and arabic for every event', function () {
    foreach (NotificationTemplateCatalog::definitions() as $key => $definition) {
        foreach (['en', 'ar'] as $locale) {
            expect($definition)->toHaveKey($locale)
                ->and(trim((string) ($definition[$locale]['subject'] ?? '')))->not->toBe('')
                ->and(trim((string) ($definition[$locale]['body'] ?? '')))->not->toBe('')
                ->and(NotificationTemplateCatalog::defaultContent($key, $locale))->not->toBeNull();
        }
    }
});

test('notification class user-facing strings have arabic translations', function () {
    $keys = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Notifications')));

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());
        preg_match_all("/__\(\s*'((?:\\\\'|[^'])*)'/", $contents, $matches);

        foreach ($matches[1] as $key) {
            $keys[stripcslashes($key)] = true;
        }
    }

    $ar = json_decode((string) file_get_contents(lang_path('ar.json')), true, 512, JSON_THROW_ON_ERROR);
    $missing = array_values(array_filter(array_keys($keys), fn (string $key): bool => ! array_key_exists($key, $ar)));

    expect($missing)->toBe([]);
});
