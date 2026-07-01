<?php

namespace App\Domain\SocialAccounts;

use App\Enums\Provider;
use App\Models\SocialAccount;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class LinkedInTokenInspector
{
    public const EXPIRY_BUFFER_SECONDS = 300;

    /**
     * LinkedIn only issues programmatic refresh tokens to approved MDP partners.
     * Standard apps must reauthorize via OAuth roughly every 60 days.
     */
    public function lacksProgrammaticRefresh(array $credentials): bool
    {
        return empty($credentials['refresh_token']);
    }

    public function tokenExpiresAt(array $credentials): ?Carbon
    {
        if (empty($credentials['expires_at'])) {
            return null;
        }

        return Carbon::parse($credentials['expires_at']);
    }

    public function isExpired(array $credentials): bool
    {
        $expiresAt = $this->tokenExpiresAt($credentials);

        if ($expiresAt === null) {
            return false;
        }

        return $expiresAt->copy()->subSeconds(self::EXPIRY_BUFFER_SECONDS)->isPast();
    }

    public function willExpireBefore(array $credentials, CarbonInterface $deadline): bool
    {
        $expiresAt = $this->tokenExpiresAt($credentials);

        if ($expiresAt === null) {
            return false;
        }

        return $expiresAt->copy()->subSeconds(self::EXPIRY_BUFFER_SECONDS)->lte($deadline);
    }

    public function needsReauthorization(SocialAccount $account, CarbonInterface $scheduledFor): bool
    {
        if ($account->provider !== Provider::LinkedIn) {
            return false;
        }

        $credentials = $account->credentials_encrypted;

        if (! $this->lacksProgrammaticRefresh($credentials)) {
            return false;
        }

        return $this->isExpired($credentials)
            || $this->willExpireBefore($credentials, $scheduledFor);
    }

    /**
     * @param  array<int>  $accountIds
     */
    public function accountsNeedingReauthorization(array $accountIds, CarbonInterface $scheduledFor): Collection
    {
        return SocialAccount::query()
            ->whereIn('id', $accountIds)
            ->where('provider', Provider::LinkedIn)
            ->get()
            ->filter(fn (SocialAccount $account) => $this->needsReauthorization($account, $scheduledFor));
    }

    /**
     * @param  array<int>  $accountIds
     */
    public function firstOwnedAccountNeedingReauthorization(array $accountIds, CarbonInterface $scheduledFor, User $user): ?SocialAccount
    {
        return $this->accountsNeedingReauthorization($accountIds, $scheduledFor)
            ->first(fn (SocialAccount $account) => $account->user_id === $user->id);
    }

    public function getConnectionWarning(?string $refreshToken): ?string
    {
        if ($refreshToken !== null && $refreshToken !== '') {
            return null;
        }

        return 'LinkedIn did not provide a refresh token for this app. Your connection will expire in about 60 days and must be renewed for scheduled posting to keep working.';
    }

    public function getAuthorizationWarning(SocialAccount $account, CarbonInterface|null $scheduledFor = null): ?string
    {
        if ($account->provider !== Provider::LinkedIn) {
            return null;
        }

        $credentials = $account->credentials_encrypted;

        if (! $this->lacksProgrammaticRefresh($credentials)) {
            return null;
        }

        $expiresAt = $this->tokenExpiresAt($credentials);

        if ($expiresAt === null) {
            return "{$account->display_name}: LinkedIn access may expire without notice. Reconnect before your post is scheduled to publish.";
        }

        if ($this->isExpired($credentials)) {
            return "{$account->display_name}: LinkedIn access has expired. Reconnect before this post can be published.";
        }

        if ($scheduledFor !== null && $this->willExpireBefore($credentials, $scheduledFor)) {
            return "{$account->display_name}: LinkedIn access expires {$expiresAt->format('M j, Y g:i A')} — before this post is scheduled. Reconnect to avoid a failed publish.";
        }

        if ($expiresAt->copy()->subDays(14)->isPast()) {
            return "{$account->display_name}: LinkedIn access expires {$expiresAt->format('M j, Y g:i A')}. Reconnect soon to keep scheduled posting working.";
        }

        return null;
    }

    public function getAccountStatusMessage(SocialAccount $account): ?string
    {
        if ($account->provider !== Provider::LinkedIn) {
            return null;
        }

        $credentials = $account->credentials_encrypted;

        if (! $this->lacksProgrammaticRefresh($credentials)) {
            return null;
        }

        $expiresAt = $this->tokenExpiresAt($credentials);

        if ($expiresAt === null) {
            return 'No refresh token — reconnect periodically to keep posting working.';
        }

        if ($this->isExpired($credentials)) {
            return "Access expired {$expiresAt->format('M j, Y')}. Reconnect to resume posting.";
        }

        return "Access expires {$expiresAt->format('M j, Y')}. Reconnect before then to avoid failed posts.";
    }
}
