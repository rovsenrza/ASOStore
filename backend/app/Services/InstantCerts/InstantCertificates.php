<?php

namespace App\Services\InstantCerts;

use App\Enums\AppleTeamStatus;
use App\Enums\DeviceRegistrationStatus as Status;
use App\Models\AppleTeam;
use App\Models\Certificate;
use App\Models\Device;
use App\Models\DeviceRegistration;
use App\Models\ExternalCertificate;
use App\Models\MembershipYear;
use App\Services\Devices\UdidHasher;
use App\Services\Signing\ProvisioningProfile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * The second registration method: instead of a slot on one of our Apple teams (24–72 h at Apple),
 * the device gets a certificate bought from the reseller API in seconds. Only for customers whose
 * registration_method is INSTANT; everyone else keeps the usual path untouched.
 *
 * The certificate's Apple team becomes an EXTERNAL team: never routed to, no ASC key. The device's
 * registration on it is ELIGIBLE at once, so the catalog opens, and every app is signed per device
 * with that one profile (ExternalSigning) by the external runner, which alone holds these keys.
 */
class InstantCertificates
{
    public const METHOD = 'INSTANT';

    public const PROVIDER = 'nekoo';

    /** Plan of orders, codes and subscriptions bought with the instant method. */
    public const PLAN = 'instant';

    public function __construct(private readonly NekooClient $api) {}

    public function enabled(): bool
    {
        return (bool) config('instant_certs.enabled') && (string) config('instant_certs.api_key') !== '';
    }

    /** Whether customers can choose the instant method when buying. */
    public function offered(): bool
    {
        return $this->enabled() && (bool) config('instant_certs.sell', true);
    }

    /**
     * A redeemed code bought with the instant method switches the account to it, as long as no
     * iPhone is on the account yet: a device already on one of our teams keeps its slot there.
     */
    public function applyCodePlan(\App\Models\User $user, ?string $plan): void
    {
        if ($plan !== self::PLAN || $user->registration_method === self::METHOD || $user->devices()->exists()) {
            return;
        }
        $user->forceFill(['registration_method' => self::METHOD])->save();
        Log::info('instant_cert.method_chosen', ['user' => $user->id]);
    }

    /** Whether this device registers through an instant certificate instead of our teams. */
    public function wantedFor(Device $device): bool
    {
        return $this->enabled() && $device->user?->registration_method === self::METHOD;
    }

    /** The device's current registration on its instant certificate, if it has a usable one. */
    public function current(Device $device): ?DeviceRegistration
    {
        return DeviceRegistration::query()
            ->where('udid_hash', $device->udid_hash)
            ->where('device_id', $device->id)
            ->where('status', Status::Eligible->value)
            ->whereHas('team', fn ($query) => $query->where('status', AppleTeamStatus::External->value))
            ->whereHas('membershipYear', fn ($query) => $query->where('status', 'ACTIVE')->where('starts_at', '<=', now())->where('ends_at', '>', now()))
            // A revoked certificate (instant-certs:check) no longer counts, so provision() buys a new one.
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('external_certificates')
                ->whereColumn('external_certificates.device_id', 'device_registrations.device_id')
                ->whereColumn('external_certificates.apple_team_id', 'device_registrations.apple_team_id')
                ->where('external_certificates.status', 'ACTIVE'))
            ->latest('id')
            ->first();
    }

    /**
     * Gets the device a certificate (its active one at the reseller, free; else a new one on the
     * family's plan) and makes it eligible on it. Null while another worker is doing it.
     *
     * @throws InstantCertificateException
     */
    public function provision(Device $device, ?string $key = null): ?DeviceRegistration
    {
        $lock = Cache::lock('instant-cert:'.$device->udid_hash, 300);
        if (! $lock->get()) {
            return null;
        }
        try {
            if ($key === null && ($current = $this->current($device)) !== null) {
                return $current;
            }
            $udid = UdidHasher::normalize((string) $device->udid_encrypted);
            $plan = null;
            $certificate = $this->activeAtReseller($udid);
            if ($certificate === null) {
                $plan = $device->device_family?->value === 'IPAD' ? config('instant_certs.plan_ipad') : config('instant_certs.plan_iphone');
                $answer = $key !== null ? $this->api->useKey($udid, $key) : $this->api->register($udid, (string) $plan);
                Log::info('instant_cert.bought', ['device' => $device->id, 'plan' => $answer['plan'] ?? $plan, 'cost' => $answer['cost'] ?? null,
                    'already' => $answer['already_registered'] ?? ($answer['action'] ?? null), 'certificate' => $answer['certificate_id'] ?? null]);
                $certificate = $answer['certificate'] ?? null;
                if (! is_array($certificate) || empty($certificate['p12'])) {
                    // 202: registered, but the files are not readable yet.
                    $certificate = $this->activeAtReseller($udid)
                        ?? throw new InstantCertificateException('registration_busy', 'Certificate not readable yet.', 60);
                }
            }

            $registration = $this->install($device, $udid, $certificate, $plan);
            // Nothing is pre-signed for a new certificate: start the most installed apps now (delta packs).
            if ($registration->wasRecentlyCreated || $key !== null) {
                try {
                    app(\App\Services\Signing\BuildWarmup::class)->forDevice($device->fresh(), (int) config('instant_certs.presign_popular', 10));
                } catch (\Throwable $e) {
                    Log::warning('instant_cert.warmup_failed', ['device' => $device->id, 'message' => $e->getMessage()]);
                }
            }

            return $registration;
        } finally {
            $lock->release();
        }
    }

    /** @return array<string, mixed>|null the device's working normal certificate, with its files */
    private function activeAtReseller(string $udid): ?array
    {
        foreach ($this->api->certificates($udid) as $certificate) {
            if (($certificate['status'] ?? null) === 'signed' && ($certificate['provision_valid'] ?? false) === true
                && ($certificate['expired'] ?? false) !== true && ($certificate['development'] ?? false) !== true
                && ! empty($certificate['p12']) && ! empty($certificate['mobileprovision'])) {
                return $certificate;
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $certificate */
    private function install(Device $device, string $udid, array $certificate, ?string $plan): DeviceRegistration
    {
        $profileBytes = (string) base64_decode((string) $certificate['mobileprovision'], true);
        $profile = ProvisioningProfile::parse($profileBytes) ?? throw new RuntimeException('Reseller profile does not parse.');
        [$certPem, $keyPem] = $this->splitP12((string) base64_decode((string) $certificate['p12'], true), (string) ($certificate['p12_password'] ?? ''));
        $x509 = openssl_x509_parse($certPem) ?: throw new RuntimeException('Reseller certificate does not parse.');
        $team = (string) ($x509['subject']['OU'] ?? '');
        $sha1 = strtoupper((string) openssl_x509_fingerprint($certPem, 'sha1'));
        if ($team === '' || $team !== $profile['team_identifier']) {
            throw new RuntimeException("Certificate team {$team} does not match the profile's {$profile['team_identifier']}.");
        }
        if (! in_array($udid, array_map(fn ($d) => UdidHasher::normalize($d), $profile['devices']), true)) {
            throw new RuntimeException('The reseller profile does not list this device.');
        }
        $applicationId = (string) ($profile['entitlements']['application-identifier'] ?? '');
        $base = preg_replace('/^'.preg_quote($team, '/').'\./', '', $applicationId);
        $base = rtrim((string) preg_replace('/\.?\*$/', '', (string) $base), '.');
        if (preg_match('/^[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)+$/', $base) !== 1) {
            $base = 'app.instant.'.strtolower($team);
        }
        $expires = $profile['expires_at'] !== null ? Carbon::createFromTimestamp($profile['expires_at']) : now()->addYear();
        $warranty = (int) ($certificate['warranty_time'] ?? 0);

        $this->writeIdentity($sha1, $certPem, $keyPem);

        return DB::transaction(function () use ($device, $certificate, $profile, $profileBytes, $x509, $team, $sha1, $base, $expires, $warranty, $plan) {
            $appleTeam = AppleTeam::query()->where('apple_team_id', $team)->lockForUpdate()->first();
            if ($appleTeam !== null && $appleTeam->status !== AppleTeamStatus::External) {
                throw new RuntimeException("Team {$team} is one of our own teams; refusing to treat it as external.");
            }
            $appleTeam ??= AppleTeam::query()->create([
                'apple_team_id' => $team,
                'name' => mb_substr('Instant · '.($x509['subject']['O'] ?? $team), 0, 120),
                'status' => AppleTeamStatus::External,
                'is_primary' => false,
                'external_bundle_base' => $base,
            ]);
            if ($appleTeam->external_bundle_base === null) {
                $appleTeam->forceFill(['external_bundle_base' => $base])->save();
            }

            $year = MembershipYear::query()->where('apple_team_id', $appleTeam->id)->where('status', 'ACTIVE')
                ->where('starts_at', '<=', now())->where('ends_at', '>', now())->latest('ends_at')->first()
                ?? MembershipYear::query()->create(['apple_team_id' => $appleTeam->id, 'starts_at' => now()->subDay(), 'ends_at' => $expires, 'status' => 'ACTIVE']);
            if ($year->ends_at->lt($expires)) {
                $year->forceFill(['ends_at' => $expires])->save();
            }

            $signing = Certificate::query()->firstOrNew(['sha1_fingerprint' => $sha1]);
            $signing->fill([
                'apple_team_id' => $appleTeam->id,
                'serial_number' => strtoupper((string) ($x509['serialNumberHex'] ?? '')),
                'common_name' => (string) ($x509['subject']['CN'] ?? ''),
                'status' => 'ACTIVE',
                'expires_at' => isset($x509['validTo_time_t']) ? Carbon::createFromTimestamp($x509['validTo_time_t']) : null,
                // Held by the external runner (it confirms at its next heartbeat); set now so presigning starts at once.
                'runner_id' => $signing->runner_id ?? \App\Models\Runner::query()->where('name', 'instant-ext')->value('id'),
            ])->save();

            $external = ExternalCertificate::query()->updateOrCreate(
                ['provider' => self::PROVIDER, 'provider_certificate_id' => (string) $certificate['id']],
                [
                    'apple_team_id' => $appleTeam->id, 'device_id' => $device->id, 'certificate_id' => $signing->id,
                    'udid_hash' => $device->udid_hash, 'plan' => $plan ?? ($certificate['plan'] ?: null),
                    'common_name' => $signing->common_name, 'sha1_fingerprint' => $sha1, 'profile_uuid' => (string) $profile['uuid'],
                    'profile_encrypted' => base64_encode($profileBytes), 'profile_expires_at' => $expires,
                    'warranty_until' => $warranty > 0 && $warranty < 4102444800 ? Carbon::createFromTimestamp($warranty) : null,
                    'status' => 'ACTIVE', 'checked_at' => now(),
                ],
            );
            // An older certificate of the device is replaced by this one.
            ExternalCertificate::query()->where('udid_hash', $device->udid_hash)->whereKeyNot($external->id)
                ->where('status', 'ACTIVE')->update(['status' => 'REPLACED', 'updated_at' => now()]);

            $registration = DeviceRegistration::query()->firstOrCreate(
                ['apple_team_id' => $appleTeam->id, 'udid_hash' => $device->udid_hash, 'membership_year_id' => $year->id],
                ['device_id' => $device->id, 'device_family' => $device->device_family, 'status' => Status::Eligible],
            );
            $registration->forceFill([
                'device_id' => $device->id,
                'status' => Status::Eligible,
                'status_reason' => null,
                'apple_device_id' => 'EXT-'.$external->id,
                'registered_at' => $registration->registered_at ?? now(),
                'eligible_at' => $registration->eligible_at ?? now(),
                'last_synced_at' => now(),
            ])->save();
            Log::info('instant_cert.installed', ['device' => $device->id, 'team' => $team, 'registration' => $registration->id, 'external' => $external->id]);

            return $registration;
        });
    }

    /** @return array{0: string, 1: string} certificate PEM, private key PEM */
    private function splitP12(string $p12, string $password): array
    {
        $file = tempnam(sys_get_temp_dir(), 'ic');
        file_put_contents($file, $p12);
        chmod($file, 0600);
        try {
            foreach ([[], ['-legacy']] as $extra) {
                $cert = new Process(array_merge(['openssl', 'pkcs12'], $extra, ['-in', $file, '-passin', 'pass:'.$password, '-nokeys', '-clcerts']));
                $key = new Process(array_merge(['openssl', 'pkcs12'], $extra, ['-in', $file, '-passin', 'pass:'.$password, '-nocerts', '-nodes']));
                $cert->run();
                $key->run();
                if ($cert->isSuccessful() && $key->isSuccessful()
                    && preg_match('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $cert->getOutput(), $c)
                    && preg_match('/-----BEGIN (?:RSA |EC )?PRIVATE KEY-----.+?-----END (?:RSA |EC )?PRIVATE KEY-----/s', $key->getOutput(), $k)) {
                    return [$c[0]."\n", $k[0]."\n"];
                }
            }
        } finally {
            @unlink($file);
        }

        throw new RuntimeException('The reseller p12 could not be opened.');
    }

    /** The external runner picks <name>.cer + <name>.key up at its next heartbeat. */
    private function writeIdentity(string $sha1, string $certPem, string $keyPem): void
    {
        $dir = rtrim((string) config('instant_certs.identities_dir'), '/');
        $name = $dir.'/ext-'.strtolower($sha1);
        if (is_file($name.'.cer') && is_file($name.'.key')) {
            return;
        }
        foreach (['.key' => $keyPem, '.cer' => $certPem] as $extension => $content) {
            $temporary = $name.$extension.'.tmp';
            if (file_put_contents($temporary, $content) === false) {
                throw new RuntimeException("Cannot write the identity into {$dir}.");
            }
            chmod($temporary, 0440);
            rename($temporary, $name.$extension);
        }
    }
}
