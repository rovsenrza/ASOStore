<?php

use App\Http\Controllers\Api\Worker\WorkerController;
use Illuminate\Support\Facades\Route;

/*
| Worker API for the macOS signing runner (IMPLEMENTATION_PLAN §5.8, D9).
| Prefix /api/worker/v1; every request is HMAC-signed (VerifyWorkerSignature).
*/

Route::post('/heartbeat', [WorkerController::class, 'heartbeat'])->name('heartbeat');
Route::post('/leases', [WorkerController::class, 'lease'])->name('leases');
Route::post('/jobs/{job}/heartbeat', [WorkerController::class, 'jobHeartbeat'])->name('jobs.heartbeat');
Route::get('/jobs/{job}/source', [WorkerController::class, 'source'])->name('jobs.source');
Route::put('/jobs/{job}/artifact', [WorkerController::class, 'upload'])->name('jobs.artifact');
Route::post('/jobs/{job}/result', [WorkerController::class, 'result'])->name('jobs.result');
