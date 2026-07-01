<?php

namespace App\Support;

use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;

final class UserTimezone
{
    /**
     * @return list<string>
     */
    public static function identifiers(): array
    {
        return timezone_identifiers_list();
    }

    /**
     * @return array<string, list<string>>
     */
    public static function groupedIdentifiers(): array
    {
        $grouped = [];

        foreach (self::identifiers() as $timezone) {
            [$region, $city] = array_pad(explode('/', $timezone, 2), 2, null);
            $group = $city === null ? 'Other' : $region;
            $grouped[$group][] = $timezone;
        }

        ksort($grouped);

        return $grouped;
    }

    public static function isValid(string $timezone): bool
    {
        return in_array($timezone, self::identifiers(), true);
    }

    public static function normalize(?string $timezone): string
    {
        if ($timezone === null || $timezone === '' || ! self::isValid($timezone)) {
            return 'UTC';
        }

        return $timezone;
    }

    public static function toUtc(string $localDatetime, string $timezone): CarbonInterface
    {
        $timezone = self::normalize($timezone);

        return Date::parse($localDatetime, $timezone)->utc();
    }

    public static function toDatetimeLocal(CarbonInterface $utc, string $timezone): string
    {
        $timezone = self::normalize($timezone);

        return Date::parse($utc)->timezone($timezone)->format('Y-m-d\TH:i');
    }

    public static function format(CarbonInterface $utc, string $timezone, string $format = 'M j, Y g:i A'): string
    {
        $timezone = self::normalize($timezone);

        return Date::parse($utc)->timezone($timezone)->format($format);
    }

    public static function label(string $timezone): string
    {
        $timezone = self::normalize($timezone);

        try {
            $offset = (new DateTimeZone($timezone))->getOffset(now($timezone));
            $hours = intdiv(abs($offset), 3600);
            $minutes = intdiv(abs($offset) % 3600, 60);
            $sign = $offset >= 0 ? '+' : '-';
            $formattedOffset = sprintf('UTC%s%02d:%02d', $sign, $hours, $minutes);

            return str_replace('_', ' ', $timezone)." ({$formattedOffset})";
        } catch (InvalidArgumentException) {
            return $timezone;
        }
    }
}
