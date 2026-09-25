<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One value of a FULL_PLAN §14 metric at a point in time.
 *
 * @property string $name
 * @property array<string, string>|null $labels
 * @property float $value
 * @property Carbon $captured_at
 */
class MetricSnapshot extends Model
{
    use MassPrunable;

    public $timestamps = false;

    protected $fillable = ['name', 'labels', 'value', 'captured_at'];

    protected function casts(): array
    {
        return ['labels' => 'array', 'value' => 'float', 'captured_at' => 'datetime'];
    }

    /**
     * Kept for 90 days (model:prune runs daily).
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('captured_at', '<', now()->subDays(90));
    }
}
