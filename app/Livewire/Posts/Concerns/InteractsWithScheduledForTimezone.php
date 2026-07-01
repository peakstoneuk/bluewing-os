<?php

namespace App\Livewire\Posts\Concerns;

use App\Support\UserTimezone;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

trait InteractsWithScheduledForTimezone
{
    protected function userTimezone(): string
    {
        return Auth::user()->timezone();
    }

    protected function scheduledForAsUtc(): ?CarbonInterface
    {
        if ($this->scheduled_for === '') {
            return null;
        }

        return UserTimezone::toUtc($this->scheduled_for, $this->userTimezone());
    }

    protected function scheduledForUtcString(): string
    {
        return $this->scheduledForAsUtc()->toDateTimeString();
    }

    /**
     * @throws ValidationException
     */
    protected function assertScheduledForIsInFuture(): void
    {
        $scheduledFor = $this->scheduledForAsUtc();

        if ($scheduledFor === null || $scheduledFor->lte(now('UTC'))) {
            throw ValidationException::withMessages([
                'scheduled_for' => __('The scheduled time must be in the future.'),
            ]);
        }
    }
}
