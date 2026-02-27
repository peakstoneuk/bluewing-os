<?php

namespace App\Services\SocialProviders\Bluesky;

use App\Enums\MediaType;
use App\Services\SocialProviders\Contracts\ProviderMediaItem;
use App\Services\SocialProviders\Contracts\ProviderPublishResult;
use App\Services\SocialProviders\Contracts\SocialProviderClient;
use App\Services\SocialProviders\Contracts\ValidationResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BlueskyClient implements SocialProviderClient
{
    protected string $baseUrl = 'https://bsky.social/xrpc';

    public function __construct(
        protected RichTextFacetsBuilder $facetsBuilder
    ) {}

    public function validateCredentials(array $credentials): ValidationResult
    {
        if (empty($credentials['handle'])) {
            return ValidationResult::failure('Missing required credential: handle');
        }

        if (empty($credentials['app_password'])) {
            return ValidationResult::failure('Missing required credential: app_password');
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

        try {
            $session = $this->createSession($credentials['handle'], $credentials['app_password']);

            if (! $session) {
                return ProviderPublishResult::failure('Failed to authenticate with Bluesky');
            }

            return $this->createPost($session, $text, $media);
        } catch (\Throwable $e) {
            Log::error('Bluesky publish failed', [
                'account' => $externalAccountId,
                'error' => $e->getMessage(),
            ]);

            return ProviderPublishResult::failure($e->getMessage());
        }
    }

    public static function credentialFields(): array
    {
        return [
            'handle' => [
                'label' => 'Handle (e.g. user.bsky.social)',
                'type' => 'text',
                'required' => true,
            ],
            'app_password' => [
                'label' => 'App Password',
                'type' => 'password',
                'required' => true,
            ],
        ];
    }

    /**
     * @return array{accessJwt: string, did: string}|null
     */
    protected function createSession(string $handle, string $appPassword): ?array
    {
        $response = Http::post("{$this->baseUrl}/com.atproto.server.createSession", [
            'identifier' => $handle,
            'password' => $appPassword,
        ]);

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json();

        return [
            'accessJwt' => $data['accessJwt'] ?? '',
            'did' => $data['did'] ?? '',
        ];
    }

    /**
     * @param  array{accessJwt: string, did: string}  $session
     * @param  ProviderMediaItem[]  $media
     */
    protected function createPost(array $session, string $text, array $media = []): ProviderPublishResult
    {
        $providerMediaIds = [];
        $embed = null;
        $hasMedia = ! empty($media);

        if ($hasMedia) {
            $hasVideo = collect($media)->contains(fn (ProviderMediaItem $m) => $m->type === MediaType::Video);

            if ($hasVideo) {
                throw new \RuntimeException('Bluesky video publishing is not yet implemented.');
            }

            $images = [];

            foreach ($media as $item) {
                $blob = $this->uploadBlob($session, $item);

                if ($blob === null) {
                    return ProviderPublishResult::failure(
                        "Failed to upload image to Bluesky: {$item->filename}"
                    );
                }

                $providerMediaIds[$item->id] = $blob['ref']['$link'] ?? '';

                $images[] = [
                    'alt' => $item->altText ?? '',
                    'image' => $blob,
                ];
            }

            $embed = [
                '$type' => 'app.bsky.embed.images',
                'images' => $images,
            ];
        }

        $facetsResult = $this->facetsBuilder->build($text, fetchLinkEmbed: ! $hasMedia);
        $facets = $facetsResult['facets'];
        $linkEmbed = $facetsResult['linkEmbed'];

        if (! $hasMedia && $linkEmbed !== null) {
            $externalEmbed = $this->buildExternalEmbed($session, $linkEmbed);
            if ($externalEmbed !== null) {
                $embed = $externalEmbed;
            }
        }

        $record = [
            '$type' => 'app.bsky.feed.post',
            'text' => $text,
            'createdAt' => now()->toIso8601String(),
        ];

        if (! empty($facets)) {
            $record['facets'] = $facets;
        }

        if ($embed !== null) {
            $record['embed'] = $embed;
        }

        $response = Http::withToken($session['accessJwt'])
            ->post("{$this->baseUrl}/com.atproto.repo.createRecord", [
                'repo' => $session['did'],
                'collection' => 'app.bsky.feed.post',
                'record' => $record,
            ]);

        if ($response->successful()) {
            $uri = $response->json('uri') ?? '';

            return ProviderPublishResult::success($uri, providerMediaIds: $providerMediaIds);
        }

        $error = $response->json('message') ?? $response->json('error') ?? 'Unknown Bluesky API error';

        return ProviderPublishResult::failure($error);
    }

    /**
     * Build app.bsky.embed.external from OG data, optionally uploading thumb image.
     *
     * @param  array{accessJwt: string, did: string}  $session
     * @param  array{title: string, description: string, uri: string, imageUrl: string|null}  $linkEmbed
     * @return array<string, mixed>|null
     */
    protected function buildExternalEmbed(array $session, array $linkEmbed): ?array
    {
        $external = [
            'uri' => $linkEmbed['uri'],
            'title' => $linkEmbed['title'],
            'description' => $linkEmbed['description'] ?? '',
        ];

        if (! empty($linkEmbed['imageUrl'])) {
            $thumbBlob = $this->uploadOgImageAsBlob($session, $linkEmbed['imageUrl']);
            if ($thumbBlob !== null) {
                $external['thumb'] = $thumbBlob;
            }
        }

        return [
            '$type' => 'app.bsky.embed.external',
            'external' => $external,
        ];
    }

    /**
     * Fetch an image URL and upload it as a blob to Bluesky (for link card thumb).
     * Max 1MB for external thumb.
     *
     * @param  array{accessJwt: string, did: string}  $session
     * @return array<string, mixed>|null
     */
    protected function uploadOgImageAsBlob(array $session, string $imageUrl): ?array
    {
        try {
            $response = Http::timeout(5)->get($imageUrl);

            if (! $response->successful()) {
                return null;
            }

            $body = $response->body();
            if (strlen($body) > 1_000_000) {
                return null;
            }

            $contentType = $response->header('Content-Type');
            $mime = explode(';', $contentType)[0] ?? 'image/jpeg';
            $mime = trim($mime);
            if (! str_starts_with($mime, 'image/')) {
                return null;
            }

            $upload = Http::withToken($session['accessJwt'])
                ->withHeaders(['Content-Type' => $mime])
                ->withBody($body, $mime)
                ->post("{$this->baseUrl}/com.atproto.repo.uploadBlob");

            if ($upload->successful() && $upload->json('blob')) {
                return $upload->json('blob');
            }
        } catch (\Throwable $e) {
            Log::debug('Bluesky OG thumb upload failed', ['url' => $imageUrl, 'error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Upload a blob to Bluesky and return the blob reference.
     *
     * @param  array{accessJwt: string, did: string}  $session
     * @return array<string, mixed>|null The blob object from the API response.
     */
    protected function uploadBlob(array $session, ProviderMediaItem $item): ?array
    {
        $response = Http::withToken($session['accessJwt'])
            ->withHeaders(['Content-Type' => $item->mimeType])
            ->withBody($item->contents, $item->mimeType)
            ->post("{$this->baseUrl}/com.atproto.repo.uploadBlob");

        if ($response->successful() && $response->json('blob')) {
            return $response->json('blob');
        }

        Log::warning('Bluesky blob upload failed', [
            'status' => $response->status(),
            'error' => $response->json('message') ?? 'unknown',
        ]);

        return null;
    }
}
