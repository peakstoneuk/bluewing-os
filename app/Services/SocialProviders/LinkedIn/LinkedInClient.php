<?php

namespace App\Services\SocialProviders\LinkedIn;

use App\Enums\MediaType;
use App\Services\SocialProviders\Contracts\ProviderMediaItem;
use App\Services\SocialProviders\Contracts\ProviderPublishResult;
use App\Services\SocialProviders\Contracts\SocialProviderClient;
use App\Services\SocialProviders\Contracts\ValidationResult;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LinkedInClient implements SocialProviderClient
{
    private const EXPIRY_BUFFER_SECONDS = 300;

    public function apiBaseUrl(): string
    {
        return rtrim(config('services.linkedin.api_base_url', 'https://api.linkedin.com'), '/');
    }

    public function validateCredentials(array $credentials): ValidationResult
    {
        if (empty($credentials['access_token'])) {
            return ValidationResult::failure('Missing required credential: access_token');
        }

        return ValidationResult::success();
    }

    public function publishText(string $externalAccountId, array $credentials, string $text): ProviderPublishResult
    {
        return $this->publish($externalAccountId, $credentials, $text);
    }

    public function publish(string $externalAccountId, array $credentials, string $text, array $media = []): ProviderPublishResult
    {
        $validation = $this->validateCredentials($credentials);

        if (! $validation->valid) {
            return ProviderPublishResult::failure($validation->message);
        }

        $refreshedCredentials = null;

        try {
            if ($this->tokenIsExpired($credentials)) {
                $refreshed = $this->refreshAccessToken($credentials);

                if (! $refreshed) {
                    return ProviderPublishResult::failure($this->tokenRefreshFailureMessage($credentials));
                }

                $credentials = $refreshed;
                $refreshedCredentials = $refreshed;
            }

            $authorUrn = $this->toMemberUrn($externalAccountId);
            $providerMediaIds = [];
            $mediaUrns = [];

            foreach ($media as $item) {
                $urn = $item->type === MediaType::Video
                    ? $this->uploadVideo($credentials, $authorUrn, $item)
                    : $this->uploadImage($credentials, $authorUrn, $item);

                if ($urn === null) {
                    return ProviderPublishResult::failure("Failed to upload media: {$item->filename}");
                }

                $providerMediaIds[$item->id] = $urn;
                $mediaUrns[] = [
                    'urn' => $urn,
                    'alt_text' => $item->altText,
                ];
            }

            $postId = $this->createPost($credentials, $authorUrn, $text, $mediaUrns);

            if ($postId === null) {
                return ProviderPublishResult::failure('Failed to create LinkedIn post.');
            }

            return ProviderPublishResult::success($postId, $refreshedCredentials, $providerMediaIds);
        } catch (\Throwable $e) {
            Log::error('LinkedIn publish failed', [
                'account' => $externalAccountId,
                'error' => $e->getMessage(),
            ]);

            return ProviderPublishResult::failure($e->getMessage());
        }
    }

    public static function credentialFields(): array
    {
        return [
            'access_token' => [
                'label' => 'Access Token (OAuth 2.0)',
                'type' => 'password',
                'required' => true,
            ],
            'refresh_token' => [
                'label' => 'Refresh Token (OAuth 2.0)',
                'type' => 'password',
                'required' => false,
            ],
            'expires_at' => [
                'label' => 'Token Expiry',
                'type' => 'text',
                'required' => false,
            ],
        ];
    }

    protected function uploadImage(array $credentials, string $ownerUrn, ProviderMediaItem $item): ?string
    {
        $response = Http::withToken($credentials['access_token'])
            ->withHeaders($this->restHeaders())
            ->post($this->apiBaseUrl().'/rest/images?action=initializeUpload', [
                'initializeUploadRequest' => [
                    'owner' => $ownerUrn,
                ],
            ]);

        if (! $response->successful()) {
            Log::warning('LinkedIn image initialize upload failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        $uploadUrl = $response->json('value.uploadUrl')
            ?? $response->json('uploadUrl')
            ?? null;

        $imageUrn = $response->json('value.image')
            ?? $response->json('image')
            ?? $response->json('value.imageUrn')
            ?? null;

        if (empty($uploadUrl) || empty($imageUrn)) {
            Log::warning('LinkedIn image initialize upload returned invalid payload');

            return null;
        }

        $upload = Http::withToken($credentials['access_token'])
            ->withHeaders(['Content-Type' => $item->mimeType])
            ->withBody($item->contents, $item->mimeType)
            ->put($uploadUrl);

        if (! $upload->successful()) {
            Log::warning('LinkedIn image binary upload failed', [
                'status' => $upload->status(),
                'body' => $upload->body(),
            ]);

            return null;
        }

        return (string) $imageUrn;
    }

    protected function uploadVideo(array $credentials, string $ownerUrn, ProviderMediaItem $item): ?string
    {
        $initialize = Http::withToken($credentials['access_token'])
            ->withHeaders($this->restHeaders())
            ->post($this->apiBaseUrl().'/rest/videos?action=initializeUpload', [
                'initializeUploadRequest' => [
                    'owner' => $ownerUrn,
                    'fileSizeBytes' => $item->sizeBytes,
                ],
            ]);

        if (! $initialize->successful()) {
            Log::warning('LinkedIn video initialize upload failed', ['status' => $initialize->status()]);

            return null;
        }

        $videoUrn = $initialize->json('value.video')
            ?? $initialize->json('video')
            ?? $initialize->json('value.videoUrn')
            ?? null;

        if (empty($videoUrn)) {
            Log::warning('LinkedIn video initialize response missing video URN');

            return null;
        }

        $instructions = $initialize->json('value.uploadInstructions')
            ?? $initialize->json('uploadInstructions')
            ?? [];

        if (! is_array($instructions) || empty($instructions)) {
            $singleUrl = $initialize->json('value.uploadUrl') ?? $initialize->json('uploadUrl');
            if ($singleUrl) {
                $instructions = [[
                    'uploadUrl' => $singleUrl,
                    'firstByte' => 0,
                    'lastByte' => strlen($item->contents) - 1,
                ]];
            }
        }

        foreach ($instructions as $instruction) {
            $uploadUrl = $instruction['uploadUrl'] ?? null;

            if (! $uploadUrl) {
                continue;
            }

            $firstByte = (int) ($instruction['firstByte'] ?? 0);
            $lastByte = (int) ($instruction['lastByte'] ?? (strlen($item->contents) - 1));
            $length = max(0, $lastByte - $firstByte + 1);
            $chunk = substr($item->contents, $firstByte, $length);

            $upload = Http::withHeaders(['Content-Type' => $item->mimeType])
                ->withBody($chunk, $item->mimeType)
                ->put($uploadUrl);

            if (! $upload->successful()) {
                Log::warning('LinkedIn video chunk upload failed', ['status' => $upload->status()]);

                return null;
            }
        }

        $finalizePayload = [
            'finalizeUploadRequest' => [
                'video' => $videoUrn,
            ],
        ];

        $uploadToken = $initialize->json('value.uploadToken') ?? $initialize->json('uploadToken');
        if ($uploadToken) {
            $finalizePayload['finalizeUploadRequest']['uploadToken'] = $uploadToken;
        }

        $finalize = Http::withToken($credentials['access_token'])
            ->withHeaders($this->restHeaders())
            ->post($this->apiBaseUrl().'/rest/videos?action=finalizeUpload', $finalizePayload);

        if (! $finalize->successful()) {
            Log::warning('LinkedIn video finalize upload failed', ['status' => $finalize->status()]);

            return null;
        }

        return (string) $videoUrn;
    }

    /**
     * @param  array<int, array{urn: string, alt_text: string|null}>  $mediaUrns
     */
    protected function createPost(array $credentials, string $authorUrn, string $text, array $mediaUrns): ?string
    {
        $payload = [
            'author' => $authorUrn,
            'commentary' => $text,
            'visibility' => 'PUBLIC',
            'lifecycleState' => 'PUBLISHED',
            'distribution' => [
                'feedDistribution' => 'MAIN_FEED',
                'targetEntities' => [],
                'thirdPartyDistributionChannels' => [],
            ],
        ];

        if (! empty($mediaUrns)) {
            $payload['content'] = $this->buildMediaContent($mediaUrns);
        }

        $url = $this->apiBaseUrl().'/rest/posts';

        Log::info('LinkedIn create post request', [
            'url' => $url,
            'headers' => $this->restHeaders(),
            'payload' => $payload,
        ]);

        $response = Http::withToken($credentials['access_token'])
            ->withHeaders($this->restHeaders())
            ->post($url, $payload);

        Log::info('LinkedIn create post response', $this->formatPostCreateResponseForLog($response));

        if ($response->successful()) {
            $postId = $this->extractCreatedPostId($response);

            if ($postId !== null) {
                return $postId;
            }

            if ($response->status() === 201) {
                Log::warning('LinkedIn create post returned 201 without a post ID; treating as success', [
                    'response' => $this->formatPostCreateResponseForLog($response),
                ]);

                return '';
            }

            Log::warning('LinkedIn create post succeeded but no post ID could be extracted', [
                'response' => $this->formatPostCreateResponseForLog($response),
            ]);

            return null;
        }

        Log::warning('LinkedIn create post failed', [
            'response' => $this->formatPostCreateResponseForLog($response),
        ]);

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatPostCreateResponseForLog(\Illuminate\Http\Client\Response $response): array
    {
        return [
            'status' => $response->status(),
            'headers' => $response->headers(),
            'body' => $response->body(),
        ];
    }

    protected function extractCreatedPostId(\Illuminate\Http\Client\Response $response): ?string
    {
        $candidates = [
            $response->header('x-restli-id'),
            $this->extractIdFromLocationHeader($response->header('Location')),
            $response->json('id'),
            $response->json('value.id'),
        ];

        foreach ($candidates as $candidate) {
            $id = $this->normalizePostId($candidate);

            if ($id !== null) {
                return $id;
            }
        }

        return null;
    }

    protected function extractIdFromLocationHeader(?string $location): ?string
    {
        if (! filled($location)) {
            return null;
        }

        $path = parse_url($location, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return null;
        }

        $segment = urldecode(basename($path));

        return str_starts_with($segment, 'urn:li:') ? $segment : null;
    }

    protected function normalizePostId(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $id = trim(urldecode($value));

        return filled($id) ? $id : null;
    }

    /**
     * @param  array<int, array{urn: string, alt_text: string|null}>  $mediaUrns
     * @return array<string, mixed>
     */
    protected function buildMediaContent(array $mediaUrns): array
    {
        $items = array_map(
            fn (array $item) => array_filter([
                'id' => $item['urn'],
                'altText' => $item['alt_text'],
            ], fn ($value) => $value !== null && $value !== ''),
            $mediaUrns,
        );

        if (count($items) === 1) {
            return ['media' => $items[0]];
        }

        return [
            'multiImage' => [
                'images' => $items,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function restHeaders(): array
    {
        return [
            'LinkedIn-Version' => $this->apiVersion(),
            'X-Restli-Protocol-Version' => '2.0.0',
        ];
    }

    protected function apiVersion(): string
    {
        $configured = config('services.linkedin.version');

        if (! empty($configured)) {
            return (string) $configured;
        }

        // LinkedIn releases versioned APIs monthly; the current month may not exist yet.
        return now()->subMonth()->format('Ym');
    }

    protected function tokenIsExpired(array $credentials): bool
    {
        if (empty($credentials['expires_at'])) {
            return false;
        }

        return Carbon::parse($credentials['expires_at'])
            ->subSeconds(self::EXPIRY_BUFFER_SECONDS)
            ->isPast();
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function refreshAccessToken(array $credentials): ?array
    {
        if (empty($credentials['refresh_token'])) {
            Log::warning('LinkedIn token refresh attempted without a refresh token');

            return null;
        }

        $clientId = config('services.linkedin.client_id');
        $clientSecret = config('services.linkedin.client_secret');

        if (empty($clientId) || empty($clientSecret)) {
            Log::warning('LinkedIn token refresh failed: missing LINKEDIN_CLIENT_ID or LINKEDIN_CLIENT_SECRET');

            return null;
        }

        try {
            $response = Http::asForm()
                ->post('https://www.linkedin.com/oauth/v2/accessToken', [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $credentials['refresh_token'],
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                ]);

            if (! $response->successful() || ! $response->json('access_token')) {
                Log::warning('LinkedIn token refresh failed', [
                    'status' => $response->status(),
                    'error' => $response->json('error') ?? 'unknown',
                    'error_description' => $response->json('error_description') ?? '',
                ]);

                return null;
            }

            $data = $response->json();

            return array_merge($credentials, [
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'] ?? $credentials['refresh_token'],
                'expires_at' => isset($data['expires_in'])
                    ? now()->addSeconds((int) $data['expires_in'])->toIso8601String()
                    : $credentials['expires_at'] ?? null,
                'scope' => $data['scope'] ?? $credentials['scope'] ?? null,
                'token_type' => $data['token_type'] ?? $credentials['token_type'] ?? 'Bearer',
            ]);
        } catch (\Throwable $e) {
            Log::error('LinkedIn token refresh exception', ['error' => $e->getMessage()]);

            return null;
        }
    }

    protected function tokenRefreshFailureMessage(array $credentials): string
    {
        if (empty($credentials['refresh_token'])) {
            return 'Your LinkedIn access token has expired and LinkedIn did not provide a refresh token for this app. Please reconnect your LinkedIn account.';
        }

        $clientId = config('services.linkedin.client_id');
        $clientSecret = config('services.linkedin.client_secret');

        if (empty($clientId) || empty($clientSecret)) {
            return 'LinkedIn token refresh is not configured (missing LINKEDIN_CLIENT_ID or LINKEDIN_CLIENT_SECRET).';
        }

        return 'LinkedIn rejected the token refresh. Please reconnect your LinkedIn account.';
    }

    protected function toMemberUrn(string $identifier): string
    {
        if (str_starts_with($identifier, 'urn:li:person:')) {
            return $identifier;
        }

        return 'urn:li:person:'.$identifier;
    }
}
