<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Shared\Responses\ApiResponse;

beforeEach(function () {
    Route::middleware('api')->group(function () {
        Route::get('/api/test-negotiation', function () {
            return ApiResponse::success(['status' => 'ok']);
        });

        Route::post('/api/test-negotiation', function () {
            return ApiResponse::success(['received' => request()->all()]);
        });

        Route::put('/api/test-negotiation', function () {
            return ApiResponse::success(['updated' => true]);
        });
    });
});

test('request rejecting application/json returns 406 Not Acceptable', function (string $acceptHeader) {
    $response = $this->get('/api/test-negotiation', [
        'Accept' => $acceptHeader,
    ]);

    $response->assertStatus(406)
        ->assertExactJson([
            'success' => false,
            'message' => 'Not Acceptable. Request must accept application/json.',
            'errors' => [],
            'code' => 'NOT_ACCEPTABLE',
        ]);

    expect($response->getContent())->toContain('"errors":{}');
})->with([
    'text/html',
    'application/xml',
    'image/png',
]);

test('mutation request with unsupported Content-Type returns 415 Unsupported Media Type', function (string $contentType, string $method) {
    $response = $this->call(
        method: $method,
        uri: '/api/test-negotiation',
        parameters: [],
        cookies: [],
        files: [],
        server: [
            'CONTENT_TYPE' => $contentType,
            'HTTP_ACCEPT' => 'application/json',
        ],
        content: 'raw payload body'
    );

    $response->assertStatus(415)
        ->assertExactJson([
            'success' => false,
            'message' => 'Unsupported Media Type. Content-Type must be application/json.',
            'errors' => [],
            'code' => 'UNSUPPORTED_MEDIA_TYPE',
        ]);

    expect($response->getContent())->toContain('"errors":{}');
})->with([
    ['text/plain', 'POST'],
    ['application/xml', 'POST'],
    ['text/html', 'PUT'],
]);

test('valid json request passes successfully through middleware', function () {
    $response = $this->postJson('/api/test-negotiation', [
        'key' => 'value',
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'received' => [
                    'key' => 'value',
                ],
            ],
        ]);
});

test('GET request with default Accept header passes successfully through middleware', function () {
    $response = $this->getJson('/api/test-negotiation');

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'status' => 'ok',
            ],
        ]);
});
