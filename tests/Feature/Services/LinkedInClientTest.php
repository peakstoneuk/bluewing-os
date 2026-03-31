<?php

use App\Enums\MediaType;
use App\Services\SocialProviders\Contracts\ProviderMediaItem;
use App\Services\SocialProviders\LinkedIn\LinkedInClient;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.linkedin.client_id' => 'linkedin-client-id',
        'services.linkedin.client_secret' => 'linkedin-client-secret',
        'services.linkedin.api_base_url' => 'https://api.linkedin.com',
        'services.linkedin.version' => '202504',
    ]);
});

test('publishes linkedin text post with bearer token', function () {
    Http::fake([
        'api.linkedin.com/rest/posts' => Http::response([
            'id' => 'urn:li:share:1234',
        ]),
    ]);

    $client = new LinkedInClient;

    $result = $client->publishText('member-123', [
        'access_token' => 'valid-linkedin-token',
        'expires_at' => now()->addHour()->toIso8601String(),
    ], 'Hello LinkedIn from Blue Wing');

    expect($result->success)->toBeTrue();
    expect($result->externalPostId)->toBe('urn:li:share:1234');
    expect($result->refreshedCredentials)->toBeNull();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.linkedin.com/rest/posts'
            && $request->hasHeader('Authorization', 'Bearer valid-linkedin-token')
            && $request->hasHeader('LinkedIn-Version', '202504')
            && $request->hasHeader('X-Restli-Protocol-Version', '2.0.0')
            && $request['author'] === 'urn:li:person:member-123'
            && $request['commentary'] === 'Hello LinkedIn from Blue Wing';
    });
});

test('fails when linkedin access token is missing', function () {
    $client = new LinkedInClient;

    $result = $client->publishText('member-123', [], 'Hello');

    expect($result->success)->toBeFalse();
    expect($result->errorMessage)->toContain('access_token');
});

test('refreshes expired linkedin token before publishing', function () {
    Http::fake([
        'www.linkedin.com/oauth/v2/accessToken' => Http::response([
            'access_token' => 'fresh-linkedin-token',
            'refresh_token' => 'fresh-refresh-token',
            'expires_in' => 7200,
            'token_type' => 'Bearer',
        ]),
        'api.linkedin.com/rest/posts' => Http::response([
            'id' => 'urn:li:share:9876',
        ]),
    ]);

    $client = new LinkedInClient;

    $result = $client->publishText('member-123', [
        'access_token' => 'expired-token',
        'refresh_token' => 'old-refresh-token',
        'expires_at' => now()->subHour()->toIso8601String(),
    ], 'Post with refreshed token');

    expect($result->success)->toBeTrue();
    expect($result->externalPostId)->toBe('urn:li:share:9876');
    expect($result->refreshedCredentials)->not->toBeNull();
    expect($result->refreshedCredentials['access_token'])->toBe('fresh-linkedin-token');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.linkedin.com/rest/posts'
            && $request->hasHeader('Authorization', 'Bearer fresh-linkedin-token');
    });
});

test('publishes linkedin post with image media', function () {
    Http::fake([
        'api.linkedin.com/rest/images?action=initializeUpload' => Http::response([
            'value' => [
                'uploadUrl' => 'https://upload.linkedin.test/image/123',
                'image' => 'urn:li:image:123',
            ],
        ]),
        'upload.linkedin.test/*' => Http::response('', 201),
        'api.linkedin.com/rest/posts' => Http::response([
            'id' => 'urn:li:share:withmedia',
        ]),
    ]);

    $client = new LinkedInClient;
    $media = [
        new ProviderMediaItem(
            id: 42,
            type: MediaType::Image,
            mimeType: 'image/png',
            contents: 'fake-image-binary',
            sizeBytes: strlen('fake-image-binary'),
            altText: 'A product screenshot',
            filename: 'image.png',
        ),
    ];

    $result = $client->publish('member-123', [
        'access_token' => 'valid-linkedin-token',
    ], 'Image post', $media);

    expect($result->success)->toBeTrue();
    expect($result->providerMediaIds[42])->toBe('urn:li:image:123');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.linkedin.com/rest/posts'
            && $request['content']['media'][0]['id'] === 'urn:li:image:123'
            && $request['content']['media'][0]['altText'] === 'A product screenshot';
    });
});
