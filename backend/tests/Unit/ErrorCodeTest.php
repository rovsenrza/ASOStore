<?php

use App\Enums\ErrorCode;

it('gives every error code a Russian fallback and an HTTP error status', function (ErrorCode $code) {
    expect($code->message())->toMatch('/\p{Cyrillic}/u')
        ->and($code->httpStatus())->toBeGreaterThanOrEqual(400)->toBeLessThan(600);
})->with(ErrorCode::cases());
