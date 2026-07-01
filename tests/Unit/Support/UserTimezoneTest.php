<?php

use App\Support\UserTimezone;
use Illuminate\Support\Facades\Date;

test('user timezone converts local datetime input to utc for storage', function () {
    $utc = UserTimezone::toUtc('2026-07-02T14:00', 'America/New_York');

    expect($utc->toDateTimeString())->toBe('2026-07-02 18:00:00');
});

test('user timezone converts utc datetime to local input value', function () {
    $local = UserTimezone::toDatetimeLocal(
        Date::parse('2026-07-02 18:00:00', 'UTC'),
        'America/New_York',
    );

    expect($local)->toBe('2026-07-02T14:00');
});

test('user timezone formats utc datetime in user timezone', function () {
    $formatted = UserTimezone::format(
        Date::parse('2026-07-02 18:00:00', 'UTC'),
        'America/New_York',
    );

    expect($formatted)->toBe('Jul 2, 2026 2:00 PM');
});

test('invalid timezone falls back to utc', function () {
    expect(UserTimezone::normalize('Not/A_Timezone'))->toBe('UTC');
});
