<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Shared\Exceptions\ApiException;
use Shared\Responses\ApiResponse;
use Shared\Utils\HttpStatus;

beforeEach(function () {
    Route::middleware('api')->group(function () {
        Route::get('/api/test-success', function () {
            return ApiResponse::success(
                data: ['id' => 1, 'name' => 'Acme Corp'],
                message: 'Entity retrieved successfully'
            );
        });

        Route::get('/api/test-success-meta', function () {
            return ApiResponse::success(
                data: [['id' => 1]],
                message: 'Items list',
                meta: ['page' => 1, 'per_page' => 25, 'total' => 100]
            );
        });

        Route::post('/api/test-created', function () {
            return ApiResponse::created(
                data: ['id' => 42],
                message: 'Resource created'
            );
        });

        Route::post('/api/test-validation', function () {
            request()->validate([
                'email' => ['required', 'email'],
                'amount' => ['required', 'integer'],
            ]);

            return ApiResponse::success();
        });

        Route::get('/api/test-domain-error', function () {
            throw new ApiException(
                message: 'Insufficient stock available for order',
                status: HttpStatus::BAD_REQUEST,
                errorCode: 'INSUFFICIENT_STOCK',
                errors: ['stock' => ['Requested 5 units, only 2 available']]
            );
        });

        Route::get('/api/test-not-found-exception', function () {
            throw ApiException::notFound('Product not found', 'PRODUCT_NOT_FOUND');
        });

        Route::get('/api/test-unhandled-exception', function () {
            throw new RuntimeException('Unexpected server error occurred');
        });
    });
});

test('success response produces exact Architecture.md envelope without meta', function () {
    $response = $this->getJson('/api/test-success');

    $response->assertStatus(200)
        ->assertExactJson([
            'success' => true,
            'message' => 'Entity retrieved successfully',
            'data' => [
                'id' => 1,
                'name' => 'Acme Corp',
            ],
        ]);
});

test('success response includes meta when provided', function () {
    $response = $this->getJson('/api/test-success-meta');

    $response->assertStatus(200)
        ->assertExactJson([
            'success' => true,
            'message' => 'Items list',
            'data' => [
                ['id' => 1],
            ],
            'meta' => [
                'page' => 1,
                'per_page' => 25,
                'total' => 100,
            ],
        ]);
});

test('created response produces 201 status and success envelope', function () {
    $response = $this->postJson('/api/test-created', []);

    $response->assertStatus(201)
        ->assertExactJson([
            'success' => true,
            'message' => 'Resource created',
            'data' => [
                'id' => 42,
            ],
        ]);
});

test('validation error produces exact 422 envelope with field errors and VALIDATION_FAILED code', function () {
    $response = $this->postJson('/api/test-validation', [
        'email' => 'invalid-email',
    ]);

    $response->assertStatus(422)
        ->assertJson([
            'success' => false,
            'code' => 'VALIDATION_FAILED',
        ])
        ->assertJsonStructure([
            'success',
            'message',
            'errors' => [
                'email',
                'amount',
            ],
            'code',
        ]);

    $json = $response->json();
    expect(array_keys($json))->toEqualCanonicalizing(['success', 'message', 'errors', 'code']);
});

test('domain error produces exact error envelope with domain error code and status', function () {
    $response = $this->getJson('/api/test-domain-error');

    $response->assertStatus(400)
        ->assertExactJson([
            'success' => false,
            'message' => 'Insufficient stock available for order',
            'errors' => [
                'stock' => ['Requested 5 units, only 2 available'],
            ],
            'code' => 'INSUFFICIENT_STOCK',
        ]);
});

test('not found ApiException produces 404 error envelope', function () {
    $response = $this->getJson('/api/test-not-found-exception');

    $response->assertStatus(404)
        ->assertExactJson([
            'success' => false,
            'message' => 'Product not found',
            'errors' => [],
            'code' => 'PRODUCT_NOT_FOUND',
        ]);

    expect($response->getContent())->toContain('"errors":{}');
});

test('unhandled exception in non-debug mode renders safe 500 error envelope', function () {
    config(['app.debug' => false]);

    $response = $this->getJson('/api/test-unhandled-exception');

    $response->assertStatus(500)
        ->assertExactJson([
            'success' => false,
            'message' => 'Server error',
            'errors' => [],
            'code' => 'INTERNAL_SERVER_ERROR',
        ]);

    expect($response->getContent())->toContain('"errors":{}');
});

test('non-API route 404 returns null from renderer and uses Laravel default html handling', function () {
    $response = $this->get('/non-existing-web-page', [
        'Accept' => 'text/html',
    ]);

    $response->assertStatus(404);
    $response->assertHeader('Content-Type', 'text/html; charset=utf-8');
    expect($response->getContent())->toContain('<html');
});
