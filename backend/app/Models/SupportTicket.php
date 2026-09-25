<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A support request from the portal (IMPLEMENTATION_PLAN P8-WEB-01).
 *
 * @property string $email
 * @property string $topic
 * @property string $message
 * @property string|null $reference_request_id
 * @property string $status
 * @property Carbon|null $created_at
 */
class SupportTicket extends Model
{
    use HasPublicId;

    public const TOPICS = ['ACTIVATION', 'DEVICE', 'INSTALL', 'ACCOUNT', 'DATA_DELETION', 'OTHER'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['status' => 'OPEN'];

    protected $fillable = ['user_id', 'email', 'topic', 'message', 'reference_request_id', 'status', 'ip'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
