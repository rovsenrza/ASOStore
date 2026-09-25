<?php

namespace App\Services\Apple;

use Illuminate\Contracts\Encryption\Encrypter;
use RuntimeException;

/**
 * Resolves a credential's vault_reference to the secret itself
 * (IMPLEMENTATION_PLAN D8). Supported references:
 *
 *   encrypted-file:secrets/apple/KEYID.p8.enc   file under storage/app/private, encrypted with APP_KEY
 *   file:/absolute/path/AuthKey_KEYID.p8        plain file outside the docroot (chmod 600)
 *   env:APPLE_PRIVATE_KEY                       environment variable (e.g. from a secrets manager)
 */
class SecretStore
{
    public function __construct(private readonly Encrypter $encrypter) {}

    public function read(string $reference): string
    {
        [$scheme, $location] = array_pad(explode(':', $reference, 2), 2, '');

        $secret = match ($scheme) {
            'encrypted-file' => $this->encrypter->decryptString($this->readFile(storage_path('app/private/'.ltrim($location, '/')))),
            'file' => $this->readFile($location),
            'env' => (string) getenv($location),
            default => throw new RuntimeException("Unsupported secret reference scheme [{$scheme}]."),
        };

        if ($secret === '') {
            throw new RuntimeException("Secret [{$scheme}:…] is empty.");
        }

        return $secret;
    }

    /**
     * Encrypts a secret into storage/app/private and returns its reference.
     */
    public function storeEncrypted(string $relativePath, #[\SensitiveParameter] string $secret): string
    {
        $path = storage_path('app/private/'.ltrim($relativePath, '/'));
        if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0700, true)) {
            throw new RuntimeException('Cannot create the secrets directory.');
        }

        file_put_contents($path, $this->encrypter->encryptString($secret));
        chmod($path, 0600);

        return 'encrypted-file:'.ltrim($relativePath, '/');
    }

    private function readFile(string $path): string
    {
        if (! is_readable($path)) {
            throw new RuntimeException('Secret file is missing or unreadable.');
        }

        return (string) file_get_contents($path);
    }
}
