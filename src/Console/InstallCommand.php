<?php

declare(strict_types=1);

namespace B1Road\Laravel\Console;

use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * `php artisan road:install` — bootstrap the Road SDK.
 *
 * - Publishes config/road.php + the Inertia JS provider.
 * - Interactively prompts for the four env values it can't infer (issuer, API
 *   base URL, client id/secret), derives the redirect URI from APP_URL, and
 *   writes them to .env — then offers to run `road:doctor`.
 * - With `--no-interaction` (CI), falls back to appending blank env stubs,
 *   exactly as before.
 */
final class InstallCommand extends Command
{
    /** @var string */
    protected $signature = 'road:install {--force : Overwrite published files if they already exist}';

    /** @var string */
    protected $description = 'Install the Road SDK: publish config + Inertia JS provider, then wire .env.';

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        $this->info('Publishing config/road.php …');
        $this->call('vendor:publish', [
            '--tag' => 'road-config',
            '--force' => $force,
        ]);

        $this->info('Publishing Inertia JS provider …');
        $this->call('vendor:publish', [
            '--tag' => 'road-inertia',
            '--force' => $force,
        ]);

        // The wizard only runs when a human is present. `--no-interaction`
        // (and any non-TTY CI run) keeps the original stub-append behavior.
        if ($this->option('no-interaction')) {
            $this->appendEnvStubs();
        } else {
            $this->runWizard();
        }

        $this->newLine();
        $this->info('Done. Next steps:');
        $this->line('  1. Run `php artisan road:doctor` to verify connectivity');
        $this->line('  2. Visit /auth/road/login to complete OIDC against the Auth Server');

        return self::SUCCESS;
    }

    /**
     * Prompt for the values that can't be inferred, derive the redirect URI
     * from APP_URL, write them to .env, and offer to run the doctor.
     */
    private function runWizard(): void
    {
        $envPath = base_path('.env');
        if (! file_exists($envPath)) {
            $this->warn('.env not found — skipping the wizard. Create .env, then re-run `road:install`.');

            return;
        }

        $this->newLine();
        $this->info('Let\'s wire Road into your .env. Get these from your Road Dev Portal client.');

        $values = [
            'ROAD_API_BASE_URL' => text(
                label: 'Road API base URL',
                default: 'https://api.road-sandbox.b1.app',
                required: true,
                hint: 'The sandbox default is shown; the portal value wins.',
            ),
            'AUTH_SERVER_ISSUER_URL' => text(
                label: 'Auth Server issuer URL',
                required: true,
                hint: 'The OIDC issuer from your portal client.',
            ),
            'AUTH_SERVER_CLIENT_ID' => text(
                label: 'Auth Server client ID',
                required: true,
            ),
            'AUTH_SERVER_CLIENT_SECRET' => password(
                label: 'Auth Server client secret',
                required: true,
                hint: 'Shown once in the portal; pasted here, never echoed.',
            ),
        ];

        // The BFF serves the callback at {APP_URL}/auth/road/callback — derive it
        // rather than asking. Register this exact URI in the portal.
        $appUrl = rtrim((string) (config('app.url') ?: 'http://localhost:8000'), '/');
        $values['AUTH_SERVER_REDIRECT_URI'] = $appUrl.'/auth/road/callback';

        // Fill in the remaining stub keys with their defaults so nothing is
        // missing after the wizard.
        $values += [
            'ROAD_API_VERSION' => 'alpha',
            'AUTH_SERVER_AUDIENCE' => '',
            'ROAD_PROXY_ENABLED' => 'true',
            'ROAD_INERTIA_ENABLED' => 'true',
        ];

        $this->writeEnvValues($envPath, $values);
        $this->info('Wrote Road config to .env (redirect URI: '.$values['AUTH_SERVER_REDIRECT_URI'].').');

        if (confirm(label: 'Run `road:doctor` now to verify the wiring?', default: true)) {
            $this->newLine();
            $this->call('road:doctor');
        }
    }

    /**
     * Set each key in .env — updating an existing key in place, appending a new
     * one. Preserves everything else.
     *
     * @param  array<string,string>  $values
     */
    private function writeEnvValues(string $envPath, array $values): void
    {
        $contents = (string) file_get_contents($envPath);
        $appended = [];

        foreach ($values as $key => $value) {
            $line = sprintf('%s=%s', $key, $this->quoteIfNeeded($value));
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
            if (preg_match($pattern, $contents) === 1) {
                // Use a callback replacement — a plain preg_replace would treat
                // `$`/`\` in the value (secrets often have them) as backreferences
                // and mangle it. The callback returns the literal line verbatim.
                $contents = (string) preg_replace_callback($pattern, static fn (): string => $line, $contents);
            } else {
                $appended[] = $line;
            }
        }

        if ($appended !== []) {
            $contents = rtrim($contents, "\n")."\n\n# Road SDK\n".implode("\n", $appended)."\n";
        }

        file_put_contents($envPath, $contents);
    }

    private function quoteIfNeeded(string $value): string
    {
        return preg_match('/\s|#/', $value) === 1 ? '"'.$value.'"' : $value;
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
            'ROAD_API_BASE_URL' => 'https://api.road.b1.app',
            'ROAD_API_VERSION' => 'alpha',
            'AUTH_SERVER_ISSUER_URL' => '',
            'AUTH_SERVER_AUDIENCE' => '',
            'AUTH_SERVER_CLIENT_ID' => '',
            'AUTH_SERVER_CLIENT_SECRET' => '',
            'AUTH_SERVER_REDIRECT_URI' => '',
            'ROAD_PROXY_ENABLED' => 'true',
            'ROAD_INERTIA_ENABLED' => 'true',
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
