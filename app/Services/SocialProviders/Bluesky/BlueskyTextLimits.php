<?php

namespace App\Services\SocialProviders\Bluesky;

/**
 * Bluesky post text limits enforced by the AT Protocol.
 */
final class BlueskyTextLimits
{
    public const MAX_GRAPHEMES = 300;

    public static function graphemeLength(string $text): int
    {
        return grapheme_strlen($text);
    }

    public static function exceedsLimit(string $text): bool
    {
        return self::graphemeLength($text) > self::MAX_GRAPHEMES;
    }

    public static function errorMessage(string $text): string
    {
        return sprintf(
            'Bluesky posts are limited to %d graphemes (this text has %d).',
            self::MAX_GRAPHEMES,
            self::graphemeLength($text),
        );
    }
}
