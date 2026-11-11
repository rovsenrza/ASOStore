<?php

namespace App\Services\InstantCerts;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The reseller registration API (docs: https://docs.nekoo.eu.org). One API key, 30 requests a
 * minute. A device that already has an active certificate of the kind asked for is never charged
 * again: /register then returns that certificate with cost 0.
 */
class NekooClient
{
    /** @return array<string, mixed> */
    public function me(): array
    {
        return $this->send('GET', '/me');
    }

    /**
     * Every certificate of the device (with the files unless $light).
     *
     * @return list<array<string, mixed>>
     */
    public function certificates(string $udid, bool $light = false): array
    {
        $answer = $this->send('GET', '/certificate', ['udid' => $udid] + ($light ? ['light' => 1] : []), allowNotFound: true);

        return array_values((array) ($answer['certificates'] ?? []));
    }

    /** @return array<string, mixed> the answer: certificate, cost, already_registered… */
    public function register(string $udid, string $plan): array
    {
        return $this->send('POST', '/register', ['udid' => $udid, 'plan' => $plan], timeout: 180);
    }

    /** @return array<string, mixed> */
    public function useKey(string $udid, string $key): array
    {
        return $this->send('POST', '/keys/use', ['udid' => $udid, 'key' => $key], timeout: 180);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $data = [], int $timeout = 60, bool $allowNotFound = false): array
    {
        $key = (string) config('instant_certs.api_key');
        if ($key === '') {
            throw new RuntimeException('INSTANT_CERTS_API_KEY is not set.');
        }
        $request = Http::withHeaders(['X-API-Key' => $key])->acceptJson()->timeout($timeout)->connectTimeout(15);
        $url = rtrim((string) config('instant_certs.base_url'), '/').$path;
        $response = $method === 'GET' ? $request->get($url, $data) : $request->post($url, $data);
        $json = (array) $response->json();
        if ($allowNotFound && $response->status() === 404) {
            return [];
        }
        if (! $response->successful() || ($json['ok'] ?? false) !== true) {
            throw new InstantCertificateException(
                (string) ($json['error'] ?? 'http_'.$response->status()),
                (string) ($json['detail'] ?? mb_substr($response->body(), 0, 300)),
                (int) ($json['retry_after'] ?? 0),
            );
        }

        return $json;
    }
}
