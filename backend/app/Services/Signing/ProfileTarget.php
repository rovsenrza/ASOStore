<?php

namespace App\Services\Signing;

use App\Enums\DeviceRegistrationStatus;
use App\Models\AppleTeam;
use App\Models\Device;
use App\Models\DeviceRegistration;
use Illuminate\Database\Eloquent\Builder;

/**
 * Whom a profile is made for: one device, or every eligible device of a team (a
 * "cohort", named by the SHA-1 of its sorted Apple device IDs). Serializable to an
 * array for the child processes that make extension profiles side by side.
 */
final class ProfileTarget
{
    /**
     * @param  list<int>  $deviceIds
     * @param  list<string>  $appleDeviceIds
     */
    private function __construct(
        public readonly AppleTeam $team,
        public readonly ?int $deviceId,
        public readonly ?string $cohort,
        public readonly array $deviceIds,
        public readonly array $appleDeviceIds,
        public readonly string $label,
    ) {}

    public static function forDevice(DeviceRegistration $registration, Device $device): self
    {
        return new self($registration->team, $device->id, null, [$device->id], [(string) $registration->apple_device_id], (string) $device->udid_hint);
    }

    /**
     * Every device whose current registration is eligible with the team.
     *
     * @throws SigningUnavailable when the team has none
     */
    public static function forTeam(AppleTeam $team): self
    {
        $registrations = DeviceRegistration::query()
            ->where('apple_team_id', $team->id)
            ->where('status', DeviceRegistrationStatus::Eligible->value)
            ->whereNotNull('apple_device_id')
            ->whereIn('id', DeviceRegistration::query()->selectRaw('MAX(id)')->groupBy('device_id'))
            ->get(['device_id', 'apple_device_id']);
        if ($registrations->isEmpty()) {
            throw new SigningUnavailable('NO_ELIGIBLE_DEVICES', "Team {$team->apple_team_id} has no eligible device.");
        }

        $appleIds = $registrations->pluck('apple_device_id')->map(fn ($id) => (string) $id)->sort()->values()->all();
        $deviceIds = $registrations->pluck('device_id')->map(fn ($id) => (int) $id)->sort()->values()->all();

        return new self($team, null, sha1(implode(',', $appleIds)), $deviceIds, $appleIds, 'team '.count($deviceIds));
    }

    public function isShared(): bool
    {
        return $this->deviceId === null;
    }

    /**
     * Limits a signing_profiles query to this target's profiles.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scope(Builder $query): Builder
    {
        $query->where('apple_team_id', $this->team->id);

        return $this->isShared()
            ? $query->whereNull('device_id')->where('cohort', $this->cohort)
            : $query->where('device_id', $this->deviceId);
    }

    /** Attributes identifying a new profile row for this target. */
    public function attributes(string $bundle): array
    {
        return [
            'apple_team_id' => $this->team->id,
            'bundle_identifier' => $bundle,
            'device_id' => $this->deviceId,
            'cohort' => $this->cohort,
            'device_ids' => $this->isShared() ? $this->deviceIds : null,
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'team_id' => $this->team->id,
            'device_id' => $this->deviceId,
            'cohort' => $this->cohort,
            'device_ids' => $this->deviceIds,
            'apple_device_ids' => $this->appleDeviceIds,
            'label' => $this->label,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(AppleTeam::query()->findOrFail($data['team_id']), $data['device_id'], $data['cohort'],
            $data['device_ids'], $data['apple_device_ids'], $data['label']);
    }
}
