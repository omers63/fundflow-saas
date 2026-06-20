<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Tests\Support\AdminPortalTranslationCatalog;

class AuditAdminPortalTranslationsCommand extends Command
{
    protected $signature = 'translations:audit-admin-portal {--sync-english : Add missing keys to ar.json using the English key as placeholder value}';

    protected $description = 'Audit tenant admin portal translation keys against lang/ar.json';

    public function handle(): int
    {
        $missing = AdminPortalTranslationCatalog::missingArabicKeys();
        $required = AdminPortalTranslationCatalog::untranslatedRequiredKeys();

        $this->info('Tenant admin translation keys scanned: '.count(AdminPortalTranslationCatalog::translationKeys()));
        $this->info('Missing from ar.json: '.count($missing));
        $this->info('Required redesign keys without Arabic: '.count($required));

        if ($required !== []) {
            $this->newLine();
            $this->warn('Required redesign keys still missing Arabic:');
            foreach ($required as $key) {
                $this->line("  - {$key}");
            }
        }

        if (! $this->option('sync-english')) {
            return $required === [] ? self::SUCCESS : self::FAILURE;
        }

        /** @var array<string, string> $arabic */
        $arabic = json_decode((string) file_get_contents(base_path('lang/ar.json')), true, 512, JSON_THROW_ON_ERROR);

        foreach ($missing as $key) {
            if (! array_key_exists($key, $arabic)) {
                $arabic[$key] = $key;
            }
        }

        ksort($arabic, SORT_NATURAL | SORT_FLAG_CASE);

        file_put_contents(
            base_path('lang/ar.json'),
            json_encode($arabic, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
        );

        $this->info('Synced '.count($missing).' missing keys into lang/ar.json using English placeholders.');

        return self::SUCCESS;
    }
}
