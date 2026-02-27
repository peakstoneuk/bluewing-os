<?php

use App\Services\SocialProviders\Bluesky\RichTextFacetsBuilder;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->builder = new RichTextFacetsBuilder;
});

test('build returns empty facets and no link embed for empty text', function () {
    $result = $this->builder->build('', fetchLinkEmbed: false);

    expect($result['facets'])->toBeArray()->toBeEmpty();
    expect($result['linkEmbed'])->toBeNull();
});

test('build detects hashtag facet with byte offsets', function () {
    $text = 'Check out #laravel and #php';
    $result = $this->builder->build($text, fetchLinkEmbed: false);

    expect($result['facets'])->toHaveCount(2);
    expect($result['facets'][0]['features'][0]['$type'])->toBe('app.bsky.richtext.facet#tag');
    expect($result['facets'][0]['features'][0]['tag'])->toBe('laravel');
    expect($result['facets'][0]['index']['byteStart'])->toBeInt();
    expect($result['facets'][0]['index']['byteEnd'])->toBeGreaterThan($result['facets'][0]['index']['byteStart']);

    expect($result['facets'][1]['features'][0]['tag'])->toBe('php');
});

test('build detects link facet with byte offsets', function () {
    $text = 'Visit https://example.com for more';
    $result = $this->builder->build($text, fetchLinkEmbed: false);

    expect($result['facets'])->toHaveCount(1);
    expect($result['facets'][0]['features'][0]['$type'])->toBe('app.bsky.richtext.facet#link');
    expect($result['facets'][0]['features'][0]['uri'])->toBe('https://example.com');
});

test('build resolves mention to DID and adds mention facet', function () {
    Http::fake([
        '*/com.atproto.identity.resolveHandle*' => Http::response(['did' => 'did:plc:resolved123'], 200),
    ]);

    $text = 'Hey @user.bsky.social check this';
    $result = $this->builder->build($text, fetchLinkEmbed: false);

    expect($result['facets'])->toHaveCount(1);
    expect($result['facets'][0]['features'][0]['$type'])->toBe('app.bsky.richtext.facet#mention');
    expect($result['facets'][0]['features'][0]['did'])->toBe('did:plc:resolved123');
});

test('build skips mention when resolve handle fails', function () {
    Http::fake([
        '*/com.atproto.identity.resolveHandle*' => Http::response([], 404),
    ]);

    $text = 'Hey @nonexistent.bsky.social';
    $result = $this->builder->build($text, fetchLinkEmbed: false);

    expect($result['facets'])->toBeEmpty();
});

test('build produces multiple facets for text with tag and link', function () {
    $text = 'See #news at https://example.com';
    $result = $this->builder->build($text, fetchLinkEmbed: false);

    expect($result['facets'])->toHaveCount(2);
    $types = array_column(array_column($result['facets'], 'features'), 0);
    $types = array_column($types, '$type');
    expect($types)->toContain('app.bsky.richtext.facet#tag');
    expect($types)->toContain('app.bsky.richtext.facet#link');
});

test('build strips trailing punctuation from hashtag tag value', function () {
    $text = 'Hello #world!';
    $result = $this->builder->build($text, fetchLinkEmbed: false);

    expect($result['facets'])->toHaveCount(1);
    expect($result['facets'][0]['features'][0]['tag'])->toBe('world');
});

test('build does not fetch link embed when fetchLinkEmbed is false', function () {
    Http::fake();

    $text = 'See https://example.com';
    $result = $this->builder->build($text, fetchLinkEmbed: false);

    expect($result['linkEmbed'])->toBeNull();
    Http::assertNothingSent();
});

test('build fetches OG data for first URL when fetchLinkEmbed is true', function () {
    $html = <<<'HTML'
    <!DOCTYPE html>
    <html><head>
    <meta property="og:title" content="Example Page">
    <meta property="og:description" content="Example description">
    <meta property="og:image" content="https://example.com/image.png">
    </head></html>
    HTML;

    Http::fake([
        'https://example.com*' => Http::response($html, 200),
    ]);

    $text = 'Check https://example.com';
    $result = $this->builder->build($text, fetchLinkEmbed: true);

    expect($result['linkEmbed'])->not->toBeNull();
    expect($result['linkEmbed']['title'])->toBe('Example Page');
    expect($result['linkEmbed']['description'])->toBe('Example description');
    expect($result['linkEmbed']['uri'])->toBe('https://example.com');
    expect($result['linkEmbed']['imageUrl'])->toBe('https://example.com/image.png');
});

test('build returns null linkEmbed when URL has no OG meta', function () {
    Http::fake([
        'https://example.com*' => Http::response('<html><body>No meta</body></html>', 200),
    ]);

    $text = 'Check https://example.com';
    $result = $this->builder->build($text, fetchLinkEmbed: true);

    expect($result['linkEmbed'])->toBeNull();
});
