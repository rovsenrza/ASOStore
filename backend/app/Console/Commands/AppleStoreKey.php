<?php

namespace App\Console\Commands;

use App\Services\Apple\SecretStore;
use Illuminate\Console\Command;

/**
 * Encrypts an App Store Connect .p8 key into storage/app/private and prints
 * the vault reference for `apple:connect` (IMPLEMENTATION_PLAN D8). Delete
 * the original .p8 afterwards.
 */
class AppleStoreKey extends Command
{
    protected $signature = 'apple:store-key {path : Path to the AuthKey_XXXXXXXXXX.p8 file} {--key-id= : Key ID (read from the file name if omitted)}';

    protected $description = 'Encrypt an App Store Connect API key and print its vault reference';

    public function handle(SecretStore $secrets): int
    {
        $path = (string) $this->argument('path');
        $key = is_readable($path) ? (string) file_get_contents($path) : '';

        if (! str_contains($key, 'BEGIN PRIVATE KEY')) {
            $this->error('Not a readable .p8 private key.');

            return self::FAILURE;
        }

        $keyId = $this->option('key-id') ?: (preg_match('/AuthKey_([A-Z0-9]+)\.p8$/', $path, $m) ? $m[1] : null);
        if (! $keyId) {
            $this->error('Pass --key-id (it could not be read from the file name).');

            return self::FAILURE;
        }

        $reference = $secrets->storeEncrypted("secrets/apple/{$keyId}.p8.enc", $key);

        $this->info("Stored. Vault reference: {$reference}");
        $this->line('Now delete the original .p8 file and run apple:connect with --key-id='.$keyId);

        return self::SUCCESS;
    }
}
