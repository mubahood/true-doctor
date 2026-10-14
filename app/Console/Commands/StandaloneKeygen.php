<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Make the Ed25519 key pair that signs standalone releases and licences
 * (docs/proposals/standalone-edition.md §5.2).
 *
 * Run once, on the SaaS server. The secret belongs in that server's `.env`
 * and in one offline backup, nowhere else; the public half travels inside
 * every release (RELEASE_KEYS.json), which is how an installed copy checks
 * the next one. With --write-env the pair goes straight into `.env` without
 * the secret ever being printed.
 */
class StandaloneKeygen extends Command
{
    protected $signature = 'standalone:keygen {--id= : Key id, e.g. 2026-a} {--write-env : Add the key to .env instead of printing it}';

    protected $description = 'Create the key pair that signs standalone releases and licences';

    public function handle(): int
    {
        if (\App\Support\Edition::isStandalone()) {
            $this->error('Signing keys belong to the SaaS server, never to a customer installation.');

            return self::FAILURE;
        }

        $id = (string) ($this->option('id') ?: now()->format('Y').'-'.strtolower(substr(bin2hex(random_bytes(2)), 0, 3)));
        if (! preg_match('/^[a-z0-9][a-z0-9.-]{1,30}$/i', $id)) {
            $this->error('A key id is letters, digits, dots and dashes.');

            return self::FAILURE;
        }

        $pair = sodium_crypto_sign_keypair();
        $secret = base64_encode(sodium_crypto_sign_secretkey($pair));
        $public = base64_encode(sodium_crypto_sign_publickey($pair));

        if (! $this->option('write-env')) {
            $this->line("RELEASE_SIGNING_KEY_ID={$id}");
            $this->line("RELEASE_SIGNING_SECRET={$secret}");
            $this->newLine();
            $this->info("Public key ({$id}): {$public}");
            $this->warn('Keep the secret in the SaaS .env and one offline backup only.');

            return self::SUCCESS;
        }

        $env = base_path('.env');
        $contents = is_file($env) ? (string) file_get_contents($env) : '';
        if (preg_match('/^RELEASE_SIGNING_SECRET=.+$/m', $contents)) {
            $this->error('.env already has a signing key. Rotating it is a deliberate act: add the new key by hand, keep the old public key trusted.');

            return self::FAILURE;
        }

        $addition = rtrim($contents, "\n")."\n\n# Standalone release and licence signing (docs/proposals/standalone-edition.md)\n"
            ."RELEASE_SIGNING_KEY_ID={$id}\nRELEASE_SIGNING_SECRET={$secret}\n";
        file_put_contents($env.'.tmp', $addition, LOCK_EX);
        @chmod($env.'.tmp', fileperms($env) & 0777 ?: 0600);
        rename($env.'.tmp', $env);

        $this->info("Signing key {$id} written to .env. Public key: {$public}");
        $this->warn('Back the secret up offline now, then run `php artisan config:clear`.');

        return self::SUCCESS;
    }
}
