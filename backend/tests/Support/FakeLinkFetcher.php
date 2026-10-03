<?php

namespace Tests\Support;

use App\Services\Imports\HostResolver;
use App\Services\Imports\LinkFetcher;

/**
 * LinkFetcher with scripted responses instead of curl. Every hop still goes through the real
 * address vetting, redirect resolution and IPA checks.
 */
final class FakeLinkFetcher extends LinkFetcher
{
    /** @var list<string> URLs requested, in order. */
    public array $requested = [];

    /**
     * @param  list<array{status?: int, location?: string, body?: string, disposition?: string}>  $responses
     * @param  array<string, list<string>>  $dns
     */
    public function __construct(public array $responses, array $dns = [])
    {
        parent::__construct(new class($dns) extends HostResolver
        {
            /** @param array<string, list<string>> $dns */
            public function __construct(private readonly array $dns) {}

            public function resolve(string $host): array
            {
                return $this->dns[$host] ?? ['93.184.216.34'];
            }
        });
    }

    protected function request(string $url, string $destination, int $maxBytes): array
    {
        $this->vettedAddress((string) parse_url($url, PHP_URL_HOST));
        $this->requested[] = $url;
        $response = array_shift($this->responses) ?? ['status' => 404];
        if (isset($response['location'])) {
            return ['status' => $response['status'] ?? 302, 'location' => $response['location'], 'content_type' => null, 'disposition' => null];
        }
        file_put_contents($destination, $response['body'] ?? '');

        return ['status' => 200, 'location' => null, 'content_type' => null, 'disposition' => $response['disposition'] ?? null];
    }
}
