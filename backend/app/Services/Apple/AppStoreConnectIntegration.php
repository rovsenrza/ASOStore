<?php

namespace App\Services\Apple;

use App\Enums\AppleDeviceStatus;
use App\Models\AppleCredential;
use App\Models\AppleTeam;
use DateTimeImmutable;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * App Store Connect API driver (FULL_PLAN §6, IMPLEMENTATION_PLAN P3-BE-02).
 * Authenticates with an ES256 JWT signed by the team's API key; the key is
 * read from the secret store per request and never logged.
 */
class AppStoreConnectIntegration implements AppleIntegration
{
    public const METRIC_429 = 'metrics:apple_api_429_count';

    private const TOKEN_TTL_SECONDS = 1200;

    public function __construct(
        private readonly SecretStore $secrets,
        private readonly string $baseUrl,
    ) {}

    public function isConfigured(AppleTeam $team): bool
    {
        return $team->activeCredential !== null;
    }

    public function findDevice(AppleTeam $team, string $udid): ?AppleDevice
    {
        $response = $this->send($team, fn (PendingRequest $http) => $http->get('/devices', [
            'filter[udid]' => $udid,
            'filter[platform]' => 'IOS',
            'limit' => 1,
        ]));

        $first = $response->json('data.0');

        return is_array($first) ? $this->present($first) : null;
    }

    public function registerDevice(AppleTeam $team, string $udid, string $name): AppleDevice
    {
        $response = $this->send($team, fn (PendingRequest $http) => $http->post('/devices', [
            'data' => [
                'type' => 'devices',
                'attributes' => ['name' => mb_substr($name, 0, 50), 'udid' => $udid, 'platform' => 'IOS'],
            ],
        ]), allowConflict: true);

        // 409: already registered with this team (e.g. by a previous attempt).
        if ($response->status() === 409) {
            return $this->findDevice($team, $udid)
                ?? throw new AppleException('Apple reported a conflict but the device was not found.', 'APPLE_CONFLICT');
        }

        return $this->present((array) $response->json('data'));
    }

    public function getDevice(AppleTeam $team, string $appleDeviceId): AppleDevice
    {
        $response = $this->send($team, fn (PendingRequest $http) => $http->get('/devices/'.rawurlencode($appleDeviceId)));

        return $this->present((array) $response->json('data'));
    }

    public function verifyCredentials(AppleTeam $team): void
    {
        $this->send($team, fn (PendingRequest $http) => $http->get('/devices', ['limit' => 1]));
    }

    public function findCertificate(AppleTeam $team, string $serialNumber): ?string
    {
        $response = $this->send($team, fn (PendingRequest $http) => $http->get('/certificates', [
            'filter[serialNumber]' => $serialNumber,
            'limit' => 1,
        ]));

        $id = $response->json('data.0.id');

        return is_string($id) ? $id : null;
    }

    public function ensureBundleId(AppleTeam $team, string $identifier, string $name): string
    {
        $path = '/bundleIds';
        $query = [
            'filter[identifier]' => $identifier,
            'filter[platform]' => 'IOS',
            'limit' => 200,
        ];

        // filter[identifier] is a prefix match: extensions can fill the first page.
        for ($page = 0; $page < 50 && $path !== null; $page++) {
            $existing = $this->send($team, fn (PendingRequest $http) => self::getPage($http, $path, $query));
            foreach ((array) $existing->json('data') as $resource) {
                if (($resource['attributes']['identifier'] ?? null) === $identifier) {
                    return (string) $resource['id'];
                }
            }

            $next = $existing->json('links.next');
            $path = is_string($next) ? (string) preg_replace('#^.*?/v1#', '', $next) : null;
            $query = [];
        }

        if ($path !== null) {
            throw new AppleException('Bundle ID search did not finish; refusing to create a duplicate.', 'APPLE_BUNDLE_LOOKUP_INCOMPLETE');
        }

        $created = $this->send($team, fn (PendingRequest $http) => $http->post('/bundleIds', [
            'data' => [
                'type' => 'bundleIds',
                'attributes' => ['identifier' => $identifier, 'name' => self::appIdName($name, $identifier), 'platform' => 'IOS'],
            ],
        ]));

        return (string) $created->json('data.id');
    }

    /**
     * Apple accepts only Latin letters, digits and spaces in an App ID's name. A Latin name
     * is kept; any other (e.g. "Яндекс Пэй") is named after the bundle ID, which is Latin:
     * com.ruappstore.yandex-pay → "Yandex Pay", com.ruappstore.yandex-pay.widget → "Yandex Pay Widget".
     */
    public static function appIdName(string $name, string $identifier): string
    {
        $clean = fn (string $text) => trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^A-Za-z0-9 ]/', ' ', $text)));
        if (preg_match('/^[\x20-\x7E]+$/', $name) === 1 && $clean($name) !== '') {
            return mb_substr($clean($name), 0, 60);
        }

        $prefix = (string) config('storefront.artifacts.own_bundle_prefix');
        $words = $prefix !== '' && str_starts_with($identifier, $prefix) ? substr($identifier, strlen($prefix)) : $identifier;

        return mb_substr(ucwords(strtolower($clean(str_replace(['.', '-', '_'], ' ', $words)))) ?: 'App', 0, 60);
    }

    public function ensureCapabilities(AppleTeam $team, string $bundleIdResource, array $capabilityTypes): void
    {
        if ($capabilityTypes === []) {
            return;
        }

        // This relationship takes no paging parameters (Apple rejects `limit`) and returns them all.
        $existing = $this->send($team, fn (PendingRequest $http) => $http->get("/bundleIds/{$bundleIdResource}/bundleIdCapabilities"));
        $enabled = array_map(fn (array $capability) => $capability['attributes']['capabilityType'] ?? null, (array) $existing->json('data'));

        foreach (array_diff($capabilityTypes, $enabled) as $type) {
            // A capability enabled concurrently answers 409; that is the state we want.
            $this->send($team, fn (PendingRequest $http) => $http->post('/bundleIdCapabilities', [
                'data' => [
                    'type' => 'bundleIdCapabilities',
                    'attributes' => ['capabilityType' => $type],
                    'relationships' => ['bundleId' => ['data' => ['type' => 'bundleIds', 'id' => $bundleIdResource]]],
                ],
            ]), allowConflict: true);
        }
    }

    public function createAdHocProfile(AppleTeam $team, string $name, string $bundleIdResource, string $certificateId, string|array $appleDeviceIds): AppleProfile
    {
        $response = $this->send($team, fn (PendingRequest $http) => $http->post('/profiles', [
            'data' => [
                'type' => 'profiles',
                'attributes' => ['name' => mb_substr($name, 0, 100), 'profileType' => 'IOS_APP_ADHOC'],
                'relationships' => [
                    'bundleId' => ['data' => ['type' => 'bundleIds', 'id' => $bundleIdResource]],
                    'certificates' => ['data' => [['type' => 'certificates', 'id' => $certificateId]]],
                    'devices' => ['data' => array_map(fn (string $id) => ['type' => 'devices', 'id' => $id], array_values((array) $appleDeviceIds))],
                ],
            ],
        ]));

        $attributes = (array) $response->json('data.attributes');
        $expires = $attributes['expirationDate'] ?? null;

        return new AppleProfile(
            (string) $response->json('data.id'),
            (string) ($attributes['uuid'] ?? ''),
            (string) ($attributes['name'] ?? $name),
            (string) ($attributes['profileContent'] ?? ''),
            is_string($expires) ? new DateTimeImmutable($expires) : null,
        );
    }

    public function countDevicesByFamily(AppleTeam $team): array
    {
        $counts = [];
        $query = ['filter[platform]' => 'IOS', 'fields[devices]' => 'deviceClass,status', 'limit' => 200];
        $path = '/devices';

        // Apple pages with links.next; the cap protects against a runaway loop.
        for ($page = 0; $page < 50 && $path !== null; $page++) {
            $response = $this->send($team, fn (PendingRequest $http) => self::getPage($http, $path, $query));
            foreach ((array) $response->json('data') as $device) {
                $family = match ($device['attributes']['deviceClass'] ?? null) {
                    'IPHONE' => 'IPHONE',
                    'IPAD' => 'IPAD',
                    'IPOD' => 'IPOD',
                    default => 'OTHER',
                };
                $counts[$family] = ($counts[$family] ?? 0) + 1;
            }

            $next = $response->json('links.next');
            $path = is_string($next) ? (string) preg_replace('#^.*?/v1#', '', $next) : null;
            $query = [];
        }

        return $counts;
    }

    public function deleteProfile(AppleTeam $team, string $profileId): void
    {
        $this->send($team, fn (PendingRequest $http) => $http->delete('/profiles/'.rawurlencode($profileId)), allowNotFound: true);
    }

    /**
     * @param  callable(PendingRequest): Response  $request
     */
    /**
     * An empty query option would replace the cursor that Apple puts in links.next.
     *
     * @param  array<string, mixed>  $query
     */
    private static function getPage(PendingRequest $http, string $path, array $query): Response
    {
        return $query === [] ? $http->get($path) : $http->get($path, $query);
    }

    private function send(AppleTeam $team, callable $request, bool $allowConflict = false, bool $allowNotFound = false): Response
    {
        $credential = $team->activeCredential ?? throw new AppleCredentialsException('The team has no active App Store Connect key.');

        try {
            $response = $request(Http::baseUrl($this->baseUrl)
                ->withToken($this->token($credential))
                ->acceptJson()
                ->timeout(20)
                ->connectTimeout(5));
        } catch (ConnectionException $e) {
            throw new AppleRetryableException('App Store Connect is unreachable: '.$e->getMessage(), 30);
        }

        if ($response->successful() || ($allowConflict && $response->status() === 409) || ($allowNotFound && $response->status() === 404)) {
            return $response;
        }

        $detail = (string) ($response->json('errors.0.detail') ?? $response->json('errors.0.title') ?? 'HTTP '.$response->status());

        throw match (true) {
            $response->status() === 429 => $this->rateLimited($response),
            $response->serverError() => new AppleRetryableException('App Store Connect error: '.$detail, 60),
            in_array($response->status(), [401, 403], true) => new AppleCredentialsException('App Store Connect rejected the API key: '.$detail),
            default => new AppleException('App Store Connect rejected the request: '.$detail),
        };
    }

    private function rateLimited(Response $response): AppleRetryableException
    {
        Cache::increment(self::METRIC_429);

        return new AppleRetryableException('App Store Connect rate limit reached.', max(1, (int) ($response->header('Retry-After') ?: 60)));
    }

    /**
     * Short-lived API token; cached for a little less than its lifetime.
     */
    private function token(AppleCredential $credential): string
    {
        return Cache::remember(
            "apple-jwt:{$credential->id}:{$credential->key_id}",
            self::TOKEN_TTL_SECONDS - 120,
            fn () => JWT::encode([
                'iss' => $credential->issuer_id,
                'iat' => now()->getTimestamp(),
                'exp' => now()->getTimestamp() + self::TOKEN_TTL_SECONDS,
                'aud' => 'appstoreconnect-v1',
            ], $this->secrets->read($credential->vault_reference), 'ES256', $credential->key_id, ['typ' => 'JWT']),
        );
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function present(array $resource): AppleDevice
    {
        $attributes = (array) ($resource['attributes'] ?? []);

        return new AppleDevice(
            (string) ($resource['id'] ?? ''),
            (string) ($attributes['udid'] ?? ''),
            AppleDeviceStatus::tryFrom((string) ($attributes['status'] ?? '')) ?? AppleDeviceStatus::Processing,
        );
    }
}
