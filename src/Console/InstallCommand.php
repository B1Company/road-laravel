<?php

declare(strict_types=1);

namespace B1Road\Laravel\Console;

use Illuminate\Console\Command;

/**
 * `php artisan road:install` — non-interactive bootstrap.
 *
 * - Publishes config/road.php.
 * - Appends Road env keys to .env (skipping any that already exist).
 * - Publishes the Inertia JS provider (`--tag=road-inertia`).
 *
 * The interactive wizard (environment picker, credential prompts,
 * Inertia/Sanctum detection) is a documented follow-up release.
 */
final class InstallCommand extends Command
{
    /** @var string */
    protected $signature = 'road:install {--force : Overwrite published files if they already exist}';

    /** @var string */
    protected $description = 'Install the Road SDK: publish config + Inertia JS provider, append .env stubs.';

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        $this->info('Publishing config/road.php …');
        $this->call('vendor:publish', [
            '--tag'      => 'road-config',
            '--force'    => $force,
        ]);

        $this->info('Publishing Inertia JS provider …');
        $this->call('vendor:publish', [
            '--tag'      => 'road-inertia',
            '--force'    => $force,
        ]);

        $this->appendEnvStubs();

        $this->newLine();
        $this->info('Done. Next steps:');
        $this->line('  1. Fill in AUTH_SERVER_* env vars and ROAD_API_BASE_URL in .env');
        $this->line('  2. Run `php artisan road:doctor` to verify connectivity');
        $this->line('  3. Visit /auth/road/login to complete OIDC against the Auth Server');

        return self::SUCCESS;
    }

    private function appendEnvStubs(): void
    {
        $envPath = base_path('.env');
        if (! file_exists($envPath)) {
            $this->warn('.env not found at '.$envPath.' — skipping env stub append.');

            return;
        }

        $contents = (string) file_get_contents($envPath);
        $additions = [
            'ROAD_API_BASE_URL'        => 'https://api.road.b1.app',
            'ROAD_API_VERSION'         => 'alpha',
            'AUTH_SERVER_ISSUER_URL'   => '',
            'AUTH_SERVER_AUDIENCE'     => '',
            'AUTH_SERVER_CLIENT_ID'    => '',
            'AUTH_SERVER_CLIENT_SECRET' => '',
            'AUTH_SERVER_REDIRECT_URI' => '',
            'ROAD_PROXY_ENABLED'       => 'true',
            'ROAD_INERTIA_ENABLED'     => 'true',
        ];

        $appended = [];
        $newLines = [];
        foreach ($additions as $key => $defaultValue) {
            if (preg_match('/^'.preg_quote($key, '/').'=/m', $contents) !== 1) {
                $newLines[] = sprintf('%s=%s', $key, $defaultValue);
                $appended[] = $key;
            }
        }

        if ($newLines === []) {
            $this->line('All Road env keys already present in .env — nothing to append.');

            return;
        }

        $block = "\n\n# Road SDK\n".implode("\n", $newLines)."\n";
        file_put_contents($envPath, $contents.$block);

        $this->info('Appended '.count($appended).' env keys to .env: '.implode(', ', $appended));
    }
}
