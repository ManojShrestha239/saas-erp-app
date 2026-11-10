<?php

declare(strict_types=1);

namespace Shared\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class FcmService
{
    private string $projectId;

    private ?string $bearerToken;

    private string $apiUrl;

    private int $timeout;

    public function __construct()
    {
        /** @var string $projectId */
        $projectId = config('services.fcm.project_id', 'nexuserp-app');
        $this->projectId = $projectId;

        /** @var string|null $bearerToken */
        $bearerToken = config('services.fcm.bearer_token') ?: config('services.fcm.server_key');
        $this->bearerToken = $bearerToken;

        /** @var string $apiUrl */
        $apiUrl = config('services.fcm.api_url', 'https://fcm.googleapis.com/v1/projects');
        $this->apiUrl = rtrim($apiUrl, '/');

        /** @var int $timeout */
        $timeout = (int) config('services.fcm.timeout', 10);
        $this->timeout = $timeout > 0 ? $timeout : 10;
    }

    public function isConfigured(): bool
    {
        return ! empty($this->projectId) && ! empty($this->bearerToken);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{
     *     success: bool,
     *     message_id: string|null,
     *     error: string|null,
     *     invalid_token: bool
     * }
     */
    public function send(string $token, string $title, string $body, array $data = []): array
    {
        $endpoint = "{$this->apiUrl}/{$this->projectId}/messages:send";

        /** @var array<string, string> $stringData */
        $stringData = [];
        foreach ($data as $key => $val) {
            $stringData[(string) $key] = is_scalar($val) ? (string) $val : (json_encode($val) ?: '');
        }

        $payload = [
            'message' => [
                'token' => $token,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'data' => $stringData,
            ],
        ];

        try {
            $client = Http::timeout($this->timeout);

            if (! empty($this->bearerToken)) {
                $authValue = str_starts_with($this->bearerToken, 'key=')
                    ? $this->bearerToken
                    : "Bearer {$this->bearerToken}";
                $client = $client->withHeaders(['Authorization' => $authValue]);
            }

            /** @var Response $response */
            $response = $client->post($endpoint, $payload);

            if ($response->successful()) {
                /** @var string|null $name */
                $name = $response->json('name');

                return [
                    'success' => true,
                    'message_id' => $name,
                    'error' => null,
                    'invalid_token' => false,
                ];
            }

            $isInvalid = $this->isUnregisteredTokenResponse($response);
            $errorMsg = (string) ($response->json('error.message') ?? $response->body());

            Log::warning('FCM message delivery failed', [
                'token_prefix' => substr($token, 0, 10).'...',
                'status' => $response->status(),
                'error' => $errorMsg,
                'invalid_token' => $isInvalid,
            ]);

            return [
                'success' => false,
                'message_id' => null,
                'error' => $errorMsg,
                'invalid_token' => $isInvalid,
            ];
        } catch (\Throwable $e) {
            Log::error('FCM HTTP request exception', [
                'error' => $e->getMessage(),
                'token_prefix' => substr($token, 0, 10).'...',
            ]);

            return [
                'success' => false,
                'message_id' => null,
                'error' => $e->getMessage(),
                'invalid_token' => false,
            ];
        }
    }

    /**
     * @param  list<string>  $tokens
     * @param  array<string, mixed>  $data
     * @return array{
     *     total: int,
     *     successful_count: int,
     *     failed_count: int,
     *     successful_tokens: list<string>,
     *     failed_tokens: list<string>,
     *     invalid_tokens: list<string>
     * }
     */
    public function sendMulticast(array $tokens, string $title, string $body, array $data = []): array
    {
        $successful = [];
        $failed = [];
        $invalid = [];

        foreach ($tokens as $token) {
            $result = $this->send($token, $title, $body, $data);

            if ($result['success']) {
                $successful[] = $token;
            } else {
                $failed[] = $token;
                if ($result['invalid_token']) {
                    $invalid[] = $token;
                }
            }
        }

        return [
            'total' => count($tokens),
            'successful_count' => count($successful),
            'failed_count' => count($failed),
            'successful_tokens' => $successful,
            'failed_tokens' => $failed,
            'invalid_tokens' => $invalid,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{
     *     success: bool,
     *     message_id: string|null,
     *     error: string|null
     * }
     */
    public function sendToTopic(string $topic, string $title, string $body, array $data = []): array
    {
        $endpoint = "{$this->apiUrl}/{$this->projectId}/messages:send";

        /** @var array<string, string> $stringData */
        $stringData = [];
        foreach ($data as $key => $val) {
            $stringData[(string) $key] = is_scalar($val) ? (string) $val : (json_encode($val) ?: '');
        }

        $payload = [
            'message' => [
                'topic' => $topic,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'data' => $stringData,
            ],
        ];

        try {
            $client = Http::timeout($this->timeout);

            if (! empty($this->bearerToken)) {
                $authValue = str_starts_with($this->bearerToken, 'key=')
                    ? $this->bearerToken
                    : "Bearer {$this->bearerToken}";
                $client = $client->withHeaders(['Authorization' => $authValue]);
            }

            /** @var Response $response */
            $response = $client->post($endpoint, $payload);

            if ($response->successful()) {
                /** @var string|null $name */
                $name = $response->json('name');

                return [
                    'success' => true,
                    'message_id' => $name,
                    'error' => null,
                ];
            }

            return [
                'success' => false,
                'message_id' => null,
                'error' => (string) ($response->json('error.message') ?? $response->body()),
            ];
        } catch (\Throwable $e) {
            Log::error('FCM sendToTopic exception', [
                'topic' => $topic,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message_id' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function isUnregisteredTokenResponse(Response $response): bool
    {
        if ($response->status() === 404) {
            return true;
        }

        $status = $response->json('error.status');
        if ($status === 'NOT_FOUND') {
            return true;
        }

        /** @var list<array<string, mixed>>|null $details */
        $details = $response->json('error.details');
        if (is_array($details)) {
            foreach ($details as $detail) {
                if (($detail['errorCode'] ?? null) === 'UNREGISTERED') {
                    return true;
                }
            }
        }

        return false;
    }
}
