<?php

namespace App\Enums;

enum Provider: string
{
    case X = 'x';
    case Bluesky = 'bluesky';
    case LinkedIn = 'linkedin';

    public function label(): string
    {
        return match ($this) {
            self::X => 'X (Twitter)',
            self::Bluesky => 'Bluesky',
            self::LinkedIn => 'LinkedIn',
        };
    }
}
