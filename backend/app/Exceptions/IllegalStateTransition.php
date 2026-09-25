<?php

namespace App\Exceptions;

use App\Enums\ErrorCode;
use App\StateMachines\StatusEnum;
use Illuminate\Support\Str;

class IllegalStateTransition extends ApiException
{
    public function __construct(string $subject, StatusEnum $from, StatusEnum $to)
    {
        parent::__construct(ErrorCode::IllegalStateTransition, details: [
            'subject' => Str::snake($subject),
            'from' => $from->value,
            'to' => $to->value,
        ]);
    }
}
