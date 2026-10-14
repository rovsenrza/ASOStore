<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A launch, crash or early exit reported by the compat shim from inside a re-signed app.
 *
 * @property int $id
 * @property string $kind
 * @property string $bundle_identifier
 * @property string|null $original_bundle_identifier
 * @property int|null $app_id
 * @property int|null $apple_team_id
 * @property string|null $app_version
 * @property string|null $os_version
 * @property string|null $device_model
 * @property string|null $shim_version
 * @property string|null $detail
 * @property string|null $backtrace
 * @property Carbon|null $happened_at
 * @property Carbon|null $created_at
 */
class AppLaunchReport extends Model
{
    public const LAUNCHED = 'LAUNCHED';

    public const CRASHED = 'CRASHED';

    public const EXITED_EARLY = 'EXITED_EARLY';

    public const KINDS = [self::LAUNCHED, self::CRASHED, self::EXITED_EARLY];

    public const UPDATED_AT = null;

    protected $fillable = [
        'kind', 'bundle_identifier', 'original_bundle_identifier', 'app_id', 'apple_team_id', 'app_version',
        'os_version', 'device_model', 'shim_version', 'detail', 'backtrace', 'happened_at',
    ];

    protected function casts(): array
    {
        return ['happened_at' => 'datetime'];
    }

    /** @return BelongsTo<CatalogApp, $this> */
    public function app(): BelongsTo
    {
        return $this->belongsTo(CatalogApp::class, 'app_id');
    }

    /** @return BelongsTo<AppleTeam, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(AppleTeam::class, 'apple_team_id');
    }
}
