<?php

namespace App\Models;

use App\Enums\DeviceFamily;
use App\Models\Concerns\HasPublicId;
use App\Services\Devices\UdidHasher;
use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A customer's iPhone/iPad. The UDID is sensitive (FULL_PLAN §7): it is
 * encrypted at rest, looked up by HMAC, and only ever displayed masked.
 *
 * @property DeviceFamily $device_family
 * @property Carbon|null $enrolled_at
 * @property Carbon|null $storefront_claimed_at
 */
class Device extends Model
{
    /** @use HasFactory<DeviceFactory> */
    use HasFactory, HasPublicId;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'device_family' => 'UNKNOWN',
    ];

    protected $fillable = ['user_id', 'product', 'device_family', 'os_version', 'name'];

    protected $hidden = ['id', 'udid_encrypted', 'udid_hash'];

    protected function casts(): array
    {
        return [
            'udid_encrypted' => 'encrypted',
            'device_family' => DeviceFamily::class,
            'enrolled_at' => 'datetime',
            'storefront_claimed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<DeviceRegistration, $this>
     */
    public function registrations(): HasMany
    {
        return $this->hasMany(DeviceRegistration::class);
    }

    /**
     * @return HasOne<DeviceRegistration, $this>
     */
    public function latestRegistration(): HasOne
    {
        return $this->hasOne(DeviceRegistration::class)->latestOfMany();
    }

    public function setUdid(string $udid): void
    {
        $normalized = UdidHasher::normalize($udid);

        $this->udid_encrypted = $normalized;
        $this->udid_hash = app(UdidHasher::class)->hash($normalized);
        $this->udid_hint = substr($normalized, -4);
    }

    /**
     * Masked form for admin screens and logs, e.g. "••••-4A2E".
     */
    public function maskedUdid(): string
    {
        return '••••-'.$this->udid_hint;
    }
}
