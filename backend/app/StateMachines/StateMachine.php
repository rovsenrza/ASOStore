<?php

namespace App\StateMachines;

use App\Exceptions\IllegalStateTransition;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Applies a status change and its audit event atomically (IMPLEMENTATION_PLAN §5.1).
 *
 * The row is re-read with a lock so two concurrent requests cannot both move
 * the same record out of the same state.
 */
class StateMachine
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * @template TModel of Model
     *
     * @param  TModel  $model
     * @param  array<string, mixed>  $extra  Other columns to update in the same write.
     * @return TModel
     */
    public function transition(
        Model $model,
        StatusEnum $to,
        ?string $reason = null,
        ?Actor $actor = null,
        string $attribute = 'status',
        array $extra = [],
    ): Model {
        return DB::transaction(function () use ($model, $to, $reason, $actor, $attribute, $extra) {
            $locked = $model->newQuery()->whereKey($model->getKey())->lockForUpdate()->firstOrFail();
            $from = $locked->getAttribute($attribute);

            if (! $from instanceof StatusEnum) {
                throw new LogicException(sprintf('%s::%s is not cast to a StatusEnum.', $model::class, $attribute));
            }

            if (! $from->canTransitionTo($to)) {
                throw new IllegalStateTransition(class_basename($model), $from, $to);
            }

            $locked->forceFill([$attribute => $to] + $extra)->save();

            $this->audit->record(
                action: Str::snake(class_basename($model)).'.'.$attribute.'_changed',
                subject: $locked,
                before: [$attribute => $from->value],
                after: [$attribute => $to->value] + array_map(
                    fn ($value) => $value instanceof \BackedEnum ? $value->value : $value,
                    $extra,
                ),
                reason: $reason,
                actor: $actor,
            );

            $model->setRawAttributes($locked->getAttributes(), true);

            return $model;
        });
    }
}
