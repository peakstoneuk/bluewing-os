<?php

namespace App\Http\Middleware;

use App\Domain\SocialAccounts\LinkedInTokenInspector;
use App\Enums\PostStatus;
use App\Models\Post;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectEditablePostToLinkedInOAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $post = $request->route('post');

        if (! $post instanceof Post) {
            return $next($request);
        }

        if (! in_array($post->status, [PostStatus::Draft, PostStatus::Scheduled], true)) {
            return $next($request);
        }

        if ($post->scheduled_for === null) {
            return $next($request);
        }

        $post->loadMissing('targets');

        $inspector = app(LinkedInTokenInspector::class);
        $accountIds = $post->targets->pluck('social_account_id')->map(fn ($id) => (int) $id)->all();

        $ownedAccount = $inspector->firstOwnedAccountNeedingReauthorization(
            $accountIds,
            $post->scheduled_for,
            $request->user(),
        );

        if ($ownedAccount === null) {
            return $next($request);
        }

        $request->session()->put('linkedin_oauth_return_to', route('posts.edit', $post));

        $oauthUrl = route('social-accounts.linkedin-oauth-redirect');

        if ($request->header('X-Livewire-Navigate')) {
            return response()->view('oauth.navigate-redirect', [
                'url' => $oauthUrl,
            ]);
        }

        return redirect($oauthUrl);
    }
}
