<?php

use App\Services\SocialProviders\Bluesky\BlueskyClient;
use App\Services\SocialProviders\Bluesky\RichTextFacetsBuilder;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake([
        '*/com.atproto.server.createSession' => Http::response([
            'accessJwt' => 'fake-jwt',
            'did' => 'did:plc:test',
        ], 200),
        '*/com.atproto.repo.createRecord' => Http::response(['uri' => 'at://did:plc:test/app.bsky.feed.post/123'], 200),
    ]);
});

test('createRecord payload includes facets when text contains hashtag', function () {
    $builder = new RichTextFacetsBuilder;
    $client = new BlueskyClient($builder);

    $client->publish(
        'external-123',
        ['handle' => 'user.bsky.social', 'app_password' => 'secret'],
        'Hello #world',
        []
    );

    Http::assertSent(function ($request) {
        if (str_contains($request->url(), 'createRecord') === false) {
            return false;
        }
        $body = $request->data();
        $record = $body['record'] ?? [];

        return isset($record['facets'])
            && is_array($record['facets'])
            && count($record['facets']) >= 1
            && ($record['facets'][0]['features'][0]['$type'] ?? '') === 'app.bsky.richtext.facet#tag';
    });
});

test('createRecord payload includes facets when text contains link', function () {
    $builder = new RichTextFacetsBuilder;
    $client = new BlueskyClient($builder);

    $client->publish(
        'external-123',
        ['handle' => 'user.bsky.social', 'app_password' => 'secret'],
        'See https://example.com',
        []
    );

    Http::assertSent(function ($request) {
        if (str_contains($request->url(), 'createRecord') === false) {
            return false;
        }
        $record = $request->data()['record'] ?? [];

        return isset($record['facets'])
            && count($record['facets']) >= 1
            && ($record['facets'][0]['features'][0]['$type'] ?? '') === 'app.bsky.richtext.facet#link';
    });
});
