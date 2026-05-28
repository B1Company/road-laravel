<?php

declare(strict_types=1);

namespace B1Road\Laravel\Console;

use B1Road\Laravel\Auth\AuthServer\TokenStore;
use Illuminate\Console\Command;

/**
 * `php artisan road:whoami` — prints the currently-stored session's user.
 *
 * Most useful in dev: after logging in via browser, run this in a
 * `php artisan tinker`-style shell to see what claims the SDK is holding.
 * Reads from TokenStore directly (no HTTP).
 */
final class WhoamiCommand extends Command
{
    /** @var string */
    protected $signature = 'road:whoami';

    /** @var string */
    protected $description = 'Print the resolved Road user from the session-backed token store.';

    public function handle(TokenStore $store): int
    {
        $tokens = $store->get();
        if ($tokens === null) {
            $this->warn('No session-stored Road tokens found. Log in at /auth/road/login first.');

            return self::FAILURE;
        }

        $payload = $tokens->userPayload;
        $this->info('Current Road user:');
        $this->line('  sub:    '.(string) ($payload['sub'] ?? '(missing)'));
        $this->line('  email:  '.(string) ($payload['email'] ?? '(missing)'));
        $this->line('  name:   '.(string) ($payload['name'] ?? $payload['preferred_username'] ?? '(missing)'));
        $this->line('  expires: '.date('c', $tokens->expiresAt));
        $this->line('  refresh available: '.($tokens->refreshToken !== null ? 'yes' : 'no'));

        return self::SUCCESS;
    }
}
