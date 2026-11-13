<?php

namespace App\Models;

use App\Enums\DeviceFamily;
use App\Enums\SubscriptionStatus;
use App\Models\Concerns\HasPublicId;
use App\Services\Devices\UdidHasher;
use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Builder;
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
 * Access is paid per device: each has its own subscriptions. A device without a UDID is
 * "pending": paid for on the account, waiting for the next new iPhone to enroll into it.
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

    protected $fillable = ['user_id', 'product', 'device_family', 'os_version', 'name', 'registration_method'];

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

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * The subscription that currently grants this device access, if any.
     *
     * @return HasOne<Subscription, $this>
     */
    public function activeSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->ofMany(
            ['id' => 'max'],
            fn (Builder $query) => $query->where('status', SubscriptionStatus::Active->value)
                ->where('starts_at', '<=', now())
                ->where(fn (Builder $end) => $end->whereNull('ends_at')->orWhere('ends_at', '>', now())),
        );
    }

    /** Has an iPhone behind it (a UDID), as opposed to a pending, paid-for place. */
    public function isEnrolled(): bool
    {
        return $this->udid_hash !== null;
    }

    public function hasAccess(): bool
    {
        return $this->activeSubscription()->exists();
    }

    /**
     * When this device's access ends (or ended): the latest end of its active subscriptions;
     * null for unlimited or never paid — see hasUnlimitedAccess() to tell them apart.
     */
    public function accessEndsAt(): ?Carbon
    {
        $end = $this->subscriptions()->where('status', SubscriptionStatus::Active->value)->max('ends_at');

        return $end ? Carbon::parse($end) : null;
    }

    public function hasUnlimitedAccess(): bool
    {
        return $this->subscriptions()->where('status', SubscriptionStatus::Active->value)->whereNull('ends_at')->exists();
    }

    /** @param Builder<Device> $query */
    public function scopeEnrolled(Builder $query): void
    {
        $query->whereNotNull('udid_hash');
    }

    /** @param Builder<Device> $query */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('udid_hash');
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
        return $this->udid_hint === null ? 'ожидает iPhone' : '••••-'.$this->udid_hint;
    }
}
