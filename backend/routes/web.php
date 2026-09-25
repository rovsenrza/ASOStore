<?php

use Illuminate\Support\Facades\Route;

/*
| The customer portal and admin panel are static sites copied into public/ by
| scripts/build-public.sh (IMPLEMENTATION_PLAN D1). On Apache, DirectoryIndex
| serves them directly; this route covers `php artisan serve`, which always
| sends "/" to Laravel.
*/

Route::get('/', function () {
    $portal = public_path('index.html');

    abort_unless(is_file($portal), 404, 'Portal not published. Run scripts/build-public.sh.');

    return response()->file($portal, ['Content-Type' => 'text/html; charset=UTF-8']);
});
