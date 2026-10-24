<?php

namespace App\Models;

use App\Enums\RoleSlug;
use App\Enums\SubscriptionStatus;
use App\Enums\UserStatus;
use App\Models\Concerns\HasPublicId;
use App\Notifications\ResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;

/**
 * @property UserStatus $status
 * @property string|null $totp_secret
 * @property Carbon|null $totp_confirmed_at
 * @property int|null $totp_last_step
 * @property Carbon|null $last_login_at
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $deletion_requested_at
 * @property Carbon|null $erased_at
 */
class User extends Authenticatable
{
    /**
     * Mirrors the column defaults so new instances are complete before a reload.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'ACTIVE',
        'locale' => 'ru',
    ];

    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasPublicId, Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'locale',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'id',
        'password',
        'remember_token',
        'totp_secret',
        'totp_last_step',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
            'totp_secret' => 'encrypted',
            'totp_confirmed_at' => 'datetime',
            'totp_last_step' => 'integer',
            'last_login_at' => 'datetime',
            'deletion_requested_at' => 'datetime',
            'erased_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')->withPivot('granted_by')->withTimestamps();
    }

    /**
     * @return HasMany<Device, $this>
     */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * The most recent subscription that currently grants access, if any.
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

    /**
     * The most recently enrolled device.
     *
     * @return HasOne<Device, $this>
     */
    public function latestDevice(): HasOne
    {
        return $this->hasOne(Device::class)->latestOfMany();
    }

    /**
     * @return HasMany<RefreshToken, $this>
     */
    public function refreshTokens(): HasMany
    {
        return $this->hasMany(RefreshToken::class);
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function isStaff(): bool
    {
        return $this->hasRole(...RoleSlug::staff());
    }

    public function hasConfirmedTotp(): bool
    {
        return $this->totp_confirmed_at !== null;
    }

    /**
     * @return list<string>
     */
    public function roleSlugs(): array
    {
        return $this->roles->pluck('slug')->sort()->values()->all();
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    /**
     * The token behind the current request: a PersonalAccessToken for bearer
     * requests, a TransientToken for session (cookie) requests.
     *
     * @return PersonalAccessToken|TransientToken|null
     */
    public function currentAccessToken()
    {
        return $this->accessToken;
    }

    public function hasRole(RoleSlug ...$roles): bool
    {
        $slugs = array_map(fn (RoleSlug $role) => $role->value, $roles);

        return $this->roles->contains(fn (Role $role) => in_array($role->slug, $slugs, true));
    }
}
