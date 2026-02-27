<?php

namespace App\Services\SocialProviders\Bluesky;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Builds Bluesky rich text facets (links, mentions, hashtags) from post text.
 *
 * Facet ranges use UTF-8 byte offsets (inclusive start, exclusive end).
 *
 * @see https://docs.bsky.app/docs/advanced-guides/post-richtext
 */
class RichTextFacetsBuilder
{
    /** Handle format: label.label (e.g. user.bsky.social). */
    protected const REGEXP_HANDLE = '([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?';

    protected const REGEXP_URL = 'https?:\/\/(www\.)?[-a-zA-Z0-9@:%._\+~#=]{1,256}\.[a-zA-Z0-9()]{1,6}\b([-a-zA-Z0-9()@:%_+.~#?&\/\/=]*[-a-zA-Z0-9@%_+~#\/\/=])?';

    protected string $baseUrl = 'https://bsky.social/xrpc';

    /** @var array<int, array{byteStart: int, byteEnd: int, features: array<int, array<string, mixed>>}> */
    protected array $facets = [];

    /**
     * @return array{facets: array<int, array{index: array{byteStart: int, byteEnd: int}, features: array<int, array<string, mixed>>}>, linkEmbed: array{title: string, description: string, uri: string, imageUrl: string|null}|null}
     */
    public function build(string $text, bool $fetchLinkEmbed = false): array
    {
        $this->facets = [];

        if ($text === '') {
            return ['facets' => [], 'linkEmbed' => null];
        }

        $rawFacets = [];
        $rawFacets = array_merge($rawFacets, $this->findTags($text));
        $rawFacets = array_merge($rawFacets, $this->findMentions($text));
        $linkFacets = $this->findUrls($text);
        $rawFacets = array_merge($rawFacets, $linkFacets);

        $this->facets = $this->sortAndDedupeFacets($rawFacets);

        $linkEmbed = null;
        if ($fetchLinkEmbed && ! empty($linkFacets)) {
            $firstUri = $linkFacets[0]['features'][0]['uri'] ?? null;
            if ($firstUri) {
                $linkEmbed = $this->fetchOgData($firstUri);
            }
        }

        $facetsForRecord = array_map(fn ($f) => [
            'index' => $f['index'],
            'features' => $f['features'],
        ], $this->facets);

        return [
            'facets' => $facetsForRecord,
            'linkEmbed' => $linkEmbed,
        ];
    }

    /**
     * @return array<int, array{index: array{byteStart: int, byteEnd: int}, features: array<int, array<string, mixed>>}>
     */
    protected function findTags(string $text): array
    {
        $out = [];
        // Hashtag: # followed by word chars; strip trailing punctuation. Bluesky tag max 64 chars.
        if (preg_match_all('/(#[\p{L}\p{N}_]+)/u', $text, $matches, PREG_OFFSET_CAPTURE) !== false) {
            foreach ($matches[0] as $match) {
                $tag = $match[0];
                $byteStart = $match[1];
                $byteEnd = $match[1] + strlen($tag);
                $tagValue = ltrim($tag, '#');
                $tagValue = preg_replace('/\p{P}+$/u', '', $tagValue) ?? $tagValue;
                if ($tagValue !== '' && strlen($tagValue) <= 64) {
                    $out[] = [
                        'index' => ['byteStart' => $byteStart, 'byteEnd' => $byteEnd],
                        'features' => [
                            ['$type' => 'app.bsky.richtext.facet#tag', 'tag' => $tagValue],
                        ],
                    ];
                }
            }
        }

        return $out;
    }

    /**
     * @return array<int, array{index: array{byteStart: int, byteEnd: int}, features: array<int, array<string, mixed>>}>
     */
    protected function findMentions(string $text): array
    {
        $out = [];
        $pattern = '/(@'.self::REGEXP_HANDLE.')/';
        if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) !== false) {
            foreach ($matches[0] as $match) {
                $handle = substr($match[0], 1); // without @
                $did = $this->resolveHandle($handle);
                if ($did === null) {
                    continue;
                }
                $byteStart = $match[1];
                $byteEnd = $match[1] + strlen($match[0]);
                $out[] = [
                    'index' => ['byteStart' => $byteStart, 'byteEnd' => $byteEnd],
                    'features' => [
                        ['$type' => 'app.bsky.richtext.facet#mention', 'did' => $did],
                    ],
                ];
            }
        }

        return $out;
    }

    /**
     * @return array<int, array{index: array{byteStart: int, byteEnd: int}, features: array<int, array<string, mixed>>}>
     */
    protected function findUrls(string $text): array
    {
        $out = [];
        $pattern = '/(?<=^|\s)('.self::REGEXP_URL.')/';
        if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) !== false) {
            foreach ($matches[0] as $match) {
                $uri = $match[0];
                $uri = rtrim($uri, '.,;!?)]');
                $byteStart = $match[1];
                $byteEnd = $byteStart + strlen($uri);
                $out[] = [
                    'index' => ['byteStart' => $byteStart, 'byteEnd' => $byteEnd],
                    'features' => [
                        ['$type' => 'app.bsky.richtext.facet#link', 'uri' => $uri],
                    ],
                ];
            }
        }

        return $out;
    }

    /**
     * @param  array<int, array{index: array{byteStart: int, byteEnd: int}, features: array<int, mixed>}>  $raw
     * @return array<int, array{index: array{byteStart: int, byteEnd: int}, features: array<int, mixed>}>
     */
    protected function sortAndDedupeFacets(array $raw): array
    {
        usort($raw, fn ($a, $b) => $a['index']['byteStart'] <=> $b['index']['byteStart']);
        $merged = [];
        $lastEnd = -1;
        foreach ($raw as $f) {
            $start = $f['index']['byteStart'];
            $end = $f['index']['byteEnd'];
            if ($start < $lastEnd) {
                continue;
            }
            $merged[] = $f;
            $lastEnd = $end;
        }

        return $merged;
    }

    protected function resolveHandle(string $handle): ?string
    {
        $response = Http::timeout(5)
            ->get("{$this->baseUrl}/com.atproto.identity.resolveHandle", [
                'handle' => $handle,
            ]);

        if (! $response->successful()) {
            Log::debug('Bluesky resolveHandle failed', ['handle' => $handle, 'status' => $response->status()]);

            return null;
        }

        $did = $response->json('did');

        return is_string($did) ? $did : null;
    }

    /**
     * @return array{title: string, description: string, uri: string, imageUrl: string|null}|null
     */
    protected function fetchOgData(string $url): ?array
    {
        try {
            $response = Http::timeout(5)->get($url);

            if (! $response->successful()) {
                return null;
            }

            $html = $response->body();
            if ($html === '') {
                return null;
            }

            $doc = new \DOMDocument;
            libxml_use_internal_errors(true);
            if (@$doc->loadHTML($html) === false) {
                return null;
            }

            $xpath = new \DOMXPath($doc);
            $og = [];
            foreach ($xpath->query('//*/meta[starts-with(@property, "og:")]') as $meta) {
                $og[$meta->getAttribute('property')] = $meta->getAttribute('content');
            }

            if (empty($og['og:title'])) {
                $titles = $xpath->query('//title');
                $og['og:title'] = $titles->count() > 0 ? (string) $titles->item(0)->nodeValue : '';
            }
            if (empty($og['og:description'])) {
                $metas = $xpath->query('//*/meta[@name="description"]');
                $og['og:description'] = $metas->count() > 0 ? (string) $metas->item(0)->getAttribute('content') : '';
            }

            $title = trim($og['og:title'] ?? '');
            $description = trim($og['og:description'] ?? '');
            if ($title === '' && $description === '') {
                return null;
            }

            $imageUrl = $og['og:image'] ?? $og['og:image:url'] ?? $og['og:image:secure_url'] ?? null;
            $imageUrl = $imageUrl ? trim($imageUrl) : null;
            if ($imageUrl !== null && ! str_starts_with($imageUrl, 'http')) {
                $imageUrl = $this->resolveRelativeUrl($url, $imageUrl);
            }

            return [
                'title' => $title ?: 'Link',
                'description' => $description,
                'uri' => $url,
                'imageUrl' => $imageUrl,
            ];
        } catch (\Throwable $e) {
            Log::debug('Bluesky OG fetch failed', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
    }

    protected function resolveRelativeUrl(string $base, string $path): string
    {
        if (str_starts_with($path, 'http')) {
            return $path;
        }
        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $path = str_starts_with($path, '/') ? $path : '/'.$path;

        return "{$scheme}://{$host}{$path}";
    }
}
