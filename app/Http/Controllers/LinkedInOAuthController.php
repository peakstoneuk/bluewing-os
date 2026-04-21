<?php

namespace App\Http\Controllers;

use App\Enums\Provider;
use App\Models\SocialAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LinkedInOAuthController extends Controller
{
    private const SCOPES = 'openid profile w_member_social';

    private function authorizeUrl(): string
    {
        return 'https://www.linkedin.com/oauth/v2/authorization';
    }

    private function tokenUrl(): string
    {
        return 'https://www.linkedin.com/oauth/v2/accessToken';
    }

    private function userInfoUrl(): string
    {
        return rtrim(config('services.linkedin.api_base_url', 'https://api.linkedin.com'), '/').'/v2/userinfo';
    }

    /**
     * Redirect the user to LinkedIn's authorization page.
     */
    public function redirect(Request $request): RedirectResponse
    {
        $clientId = config('services.linkedin.client_id');

        if (empty($clientId)) {
            return redirect()
                ->route('social-accounts.connect-linkedin')
                ->with('error', 'LinkedIn OAuth Client ID is not configured. Set LINKEDIN_CLIENT_ID in your .env file.');
        }

        $state = Str::random(40);
        $request->session()->put('linkedin_oauth_state', $state);

        $params = http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $this->redirectUri(),
            'scope' => self::SCOPES,
            'state' => $state,
        ]);

        return redirect()->away($this->authorizeUrl().'?'.$params);
    }

    /**
     * Handle the callback from LinkedIn after authorization.
     */
    public function callback(Request $request): RedirectResponse
    {
        if ($request->has('error')) {
            $description = $request->input('error_description', $request->input('error'));

            return redirect()
                ->route('social-accounts.connect-linkedin')
                ->with('error', "LinkedIn authorization was denied: {$description}");
        }

        $storedState = $request->session()->pull('linkedin_oauth_state');

        if (! $storedState || $request->input('state') !== $storedState) {
            return redirect()
                ->route('social-accounts.connect-linkedin')
                ->with('error', 'Invalid OAuth state. Please try connecting again.');
        }

        $code = $request->input('code');

        if (empty($code)) {
            return redirect()
                ->route('social-accounts.connect-linkedin')
                ->with('error', 'No authorization code received from LinkedIn.');
        }

        $tokens = $this->exchangeCodeForTokens($code);

        if (! $tokens) {
            return redirect()
                ->route('social-accounts.connect-linkedin')
                ->with('error', 'Failed to exchange authorization code for tokens. Please try again.');
        }

        $userInfo = $this->fetchUserInfo($tokens['access_token']);

        if (! $userInfo) {
            return redirect()
                ->route('social-accounts.connect-linkedin')
                ->with('error', 'Failed to retrieve your LinkedIn profile. Please try again.');
        }

        SocialAccount::updateOrCreate(
            [
                'provider' => Provider::LinkedIn,
                'external_identifier' => $userInfo['sub'],
            ],
            [
                'user_id' => $request->user()->id,
                'display_name' => $userInfo['name'],
                'credentials_encrypted' => [
                    'access_token' => $tokens['access_token'],
                    'refresh_token' => $tokens['refresh_token'] ?? null,
                    'expires_at' => isset($tokens['expires_in'])
                        ? now()->addSeconds((int) $tokens['expires_in'])->toIso8601String()
                        : null,
                    'scope' => $tokens['scope'] ?? null,
                    'token_type' => $tokens['token_type'] ?? 'Bearer',
                    'member_id' => $userInfo['sub'],
                ],
            ],
        );

        return redirect()
            ->route('social-accounts.index')
            ->with('message', "LinkedIn account {$userInfo['name']} connected successfully.");
    }

    /**
     * Exchange the authorization code for an access token.
     *
     * @return array<string, mixed>|null
     */
    protected function exchangeCodeForTokens(string $code): ?array
    {
        try {
            $response = Http::asForm()
                ->post($this->tokenUrl(), [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $this->redirectUri(),
                    'client_id' => config('services.linkedin.client_id'),
                    'client_secret' => config('services.linkedin.client_secret'),
                ]);

            if ($response->successful() && $response->json('access_token')) {
                return $response->json();
            }

            Log::warning('LinkedIn token exchange failed', [
                'status' => $response->status(),
                'error' => $response->json('error') ?? 'unknown',
                'error_description' => $response->json('error_description') ?? '',
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::error('LinkedIn token exchange exception', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Fetch the authenticated user's LinkedIn profile.
     *
     * @return array{sub: string, name: string}|null
     */
    protected function fetchUserInfo(string $accessToken): ?array
    {
        try {
            $response = Http::withToken($accessToken)->get($this->userInfoUrl());

            if (! $response->successful()) {
                Log::warning('LinkedIn user info fetch failed', [
                    'status' => $response->status(),
                    'error' => $response->json('message') ?? 'unknown',
                ]);

                return null;
            }

            $data = $response->json();
            $sub = $data['sub'] ?? null;
            $name = $data['name'] ?? ($data['given_name'] ?? null);

            if (empty($sub) || empty($name)) {
                Log::warning('LinkedIn user info payload missing required fields', [
                    'has_sub' => ! empty($sub),
                    'has_name' => ! empty($name),
                ]);

                return null;
            }

            return [
                'sub' => (string) $sub,
                'name' => (string) $name,
            ];
        } catch (\Throwable $e) {
            Log::error('LinkedIn user info exception', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Build the full redirect URI from config.
     */
    protected function redirectUri(): string
    {
        $configured = config('services.linkedin.redirect_uri');

        if (str_starts_with($configured, 'http')) {
            return $configured;
        }

        return rtrim(config('app.url'), '/').'/'.ltrim($configured, '/');
    }
}
