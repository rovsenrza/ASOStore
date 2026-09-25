<?php

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Support\Facades\Route;

it('wraps the health check in the envelope and assigns a request id', function () {
    $response = $this->getJson('/api/v1/health');

    $requestId = $response->headers->get('X-Request-Id');

    $response->assertOk()
        ->assertJsonPath('data.status', 'ok')
        ->assertJsonPath('data.checks.database', 'ok')
        ->assertJsonPath('meta.request_id', $requestId)
        ->assertJsonPath('error', null);

    expect($requestId)->toMatch('/^[0-9a-z]{26}$/');
});

it('echoes a well-formed client request id', function () {
    $this->getJson('/api/v1/health', ['X-Request-Id' => 'ios-4f1c2a9e-7b1d'])
        ->assertHeader('X-Request-Id', 'ios-4f1c2a9e-7b1d')
        ->assertJsonPath('meta.request_id', 'ios-4f1c2a9e-7b1d');
});

it('replaces a malformed client request id', function () {
    $response = $this->getJson('/api/v1/health', ['X-Request-Id' => "bad id\n<script>"]);

    expect($response->headers->get('X-Request-Id'))->toMatch('/^[0-9a-z]{26}$/');
});

it('sets security headers with an enforced CSP, and HSTS only over HTTPS', function () {
    $this->getJson('/api/v1/health')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Content-Security-Policy', SecurityHeaders::CSP)
        ->assertHeaderMissing('Content-Security-Policy-Report-Only')
        ->assertHeaderMissing('Strict-Transport-Security');

    $this->getJson('https://localhost/api/v1/health')
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');

    config(['storefront.security.csp_report_only' => true]);
    $this->getJson('/api/v1/health')->assertHeader('Content-Security-Policy-Report-Only');
});

it('returns NOT_FOUND for unknown API routes', function () {
    $this->getJson('/api/v1/does-not-exist')
        ->assertNotFound()
        ->assertJsonPath('data', null)
        ->assertJsonPath('error.code', 'NOT_FOUND')
        ->assertJsonPath('error.message', 'Запрошенный объект не найден.');
});

it('returns METHOD_NOT_ALLOWED for the wrong verb', function () {
    $this->postJson('/api/v1/health')
        ->assertStatus(405)
        ->assertJsonPath('error.code', 'METHOD_NOT_ALLOWED');
});

it('returns VALIDATION_FAILED with field details', function () {
    $this->getJson('/api/v1/apps?per_page=500')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['details' => ['fields' => ['per_page']]]]);
});

it('hides internal error details when debug is off', function () {
    config(['app.debug' => false]);
    Route::get('/api/v1/_test/boom', fn () => throw new RuntimeException('database password is hunter2'));

    $response = $this->getJson('/api/v1/_test/boom');

    $response->assertStatus(500)->assertJsonPath('error.code', 'INTERNAL');
    expect($response->getContent())->not->toContain('hunter2');
});
