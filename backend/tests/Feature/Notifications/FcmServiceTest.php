<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use Illuminate\Support\Facades\Http;
use Shared\Services\FcmService;

test('FcmService sends single notification via HTTP client with correct v1 payload', function (): void {
    config([
        'services.fcm.project_id' => 'test-project',
        'services.fcm.bearer_token' => 'mock-bearer-token',
        'services.fcm.api_url' => 'https://fcm.googleapis.com/v1/projects',
    ]);

    Http::fake([
        'https://fcm.googleapis.com/v1/projects/test-project/messages:send' => Http::response([
            'name' => 'projects/test-project/messages/msg_12345',
        ], 200),
    ]);

    $service = new FcmService;
    $result = $service->send(
        token: 'device_token_abc',
        title: 'Test Notification',
        body: 'Hello NexusERP',
        data: ['type' => 'test_alert', 'entity_id' => '01ABC'],
    );

    expect($result['success'])->toBeTrue()
        ->and($result['message_id'])->toBe('projects/test-project/messages/msg_12345')
        ->and($result['invalid_token'])->toBeFalse();

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://fcm.googleapis.com/v1/projects/test-project/messages:send'
            && $request->header('Authorization')[0] === 'Bearer mock-bearer-token'
            && $request['message']['token'] === 'device_token_abc'
            && $request['message']['notification']['title'] === 'Test Notification'
            && $request['message']['notification']['body'] === 'Hello NexusERP'
            && $request['message']['data']['type'] === 'test_alert'
            && $request['message']['data']['entity_id'] === '01ABC';
    });
});

test('FcmService detects unregistered token when FCM returns NOT_FOUND or UNREGISTERED', function (): void {
    config([
        'services.fcm.project_id' => 'test-project',
        'services.fcm.bearer_token' => 'mock-bearer-token',
    ]);

    Http::fake([
        'https://fcm.googleapis.com/v1/projects/test-project/messages:send' => Http::response([
            'error' => [
                'code' => 404,
                'message' => 'Requested entity was not found.',
                'status' => 'NOT_FOUND',
                'details' => [
                    [
                        '@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError',
                        'errorCode' => 'UNREGISTERED',
                    ],
                ],
            ],
        ], 404),
    ]);

    $service = new FcmService;
    $result = $service->send(
        token: 'expired_token',
        title: 'Test Alert',
        body: 'Test Body',
    );

    expect($result['success'])->toBeFalse()
        ->and($result['invalid_token'])->toBeTrue()
        ->and($result['message_id'])->toBeNull();
});

test('FcmService sendMulticast batches delivery and aggregates successful and invalid tokens', function (): void {
    config([
        'services.fcm.project_id' => 'test-project',
        'services.fcm.bearer_token' => 'mock-bearer-token',
    ]);

    Http::fake([
        'https://fcm.googleapis.com/v1/projects/test-project/messages:send' => function ($request) {
            $token = $request['message']['token'];
            if ($token === 'token_valid') {
                return Http::response(['name' => 'projects/test-project/messages/ok_1'], 200);
            }
            if ($token === 'token_unregistered') {
                return Http::response([
                    'error' => [
                        'code' => 404,
                        'message' => 'Token unregistered',
                        'status' => 'NOT_FOUND',
                        'details' => [['errorCode' => 'UNREGISTERED']],
                    ],
                ], 404);
            }

            return Http::response([
                'error' => ['code' => 500, 'message' => 'Server error'],
            ], 500);
        },
    ]);

    $service = new FcmService;
    $result = $service->sendMulticast(
        tokens: ['token_valid', 'token_unregistered', 'token_server_error'],
        title: 'Batch Alert',
        body: 'Multiple devices alert',
    );

    expect($result['total'])->toBe(3)
        ->and($result['successful_count'])->toBe(1)
        ->and($result['failed_count'])->toBe(2)
        ->and($result['successful_tokens'])->toBe(['token_valid'])
        ->and($result['invalid_tokens'])->toBe(['token_unregistered']);
});

test('FcmService sendToTopic dispatches topic notification correctly', function (): void {
    config([
        'services.fcm.project_id' => 'test-project',
        'services.fcm.bearer_token' => 'mock-bearer-token',
    ]);

    Http::fake([
        'https://fcm.googleapis.com/v1/projects/test-project/messages:send' => Http::response([
            'name' => 'projects/test-project/messages/topic_msg_777',
        ], 200),
    ]);

    $service = new FcmService;
    $result = $service->sendToTopic(
        topic: 'tenant-alerts-global',
        title: 'System Notice',
        body: 'Scheduled maintenance',
        data: ['action' => 'maintenance'],
    );

    expect($result['success'])->toBeTrue()
        ->and($result['message_id'])->toBe('projects/test-project/messages/topic_msg_777');

    Http::assertSent(function ($request): bool {
        return $request['message']['topic'] === 'tenant-alerts-global'
            && $request['message']['notification']['title'] === 'System Notice'
            && $request['message']['data']['action'] === 'maintenance';
    });
});
