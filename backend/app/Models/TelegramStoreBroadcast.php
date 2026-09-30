<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramStoreBroadcast extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'admin_chat_id' => 'integer',
            'from_chat_id' => 'integer',
            'message_id' => 'integer',
            'cursor_id' => 'integer',
            'total' => 'integer',
            'sent' => 'integer',
            'failed' => 'integer',
            'finished_at' => 'datetime',
        ];
    }
}
