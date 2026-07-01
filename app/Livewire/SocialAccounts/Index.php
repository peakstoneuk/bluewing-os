<?php

namespace App\Livewire\SocialAccounts;

use App\Domain\SocialAccounts\GetAccessibleAccountsQuery;
use App\Domain\SocialAccounts\LinkedInTokenInspector;
use App\Enums\Provider;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Social Accounts')]
class Index extends Component
{
    public function deleteSocialAccount(int $id): void
    {
        $account = SocialAccount::findOrFail($id);

        $this->authorize('delete', $account);

        $account->delete();

        session()->flash('message', 'Social account disconnected.');
    }

    public function reauthorizeLinkedIn(int $accountId): void
    {
        $account = SocialAccount::findOrFail($accountId);

        $this->authorize('update', $account);

        if ($account->provider !== Provider::LinkedIn || $account->user_id !== Auth::id()) {
            abort(403);
        }

        session()->put('linkedin_oauth_return_to', route('social-accounts.index'));

        $this->redirect(route('social-accounts.linkedin-oauth-redirect'), navigate: false);
    }

    public function render()
    {
        $accounts = (new GetAccessibleAccountsQuery(Auth::user()))->get();
        $inspector = app(LinkedInTokenInspector::class);

        $linkedInStatuses = $accounts
            ->filter(fn (SocialAccount $account) => $account->provider === Provider::LinkedIn)
            ->mapWithKeys(fn (SocialAccount $account) => [
                $account->id => [
                    'message' => $inspector->getAccountStatusMessage($account),
                    'expires_at' => $inspector->tokenExpiresAt($account->credentials_encrypted)?->format('M j, Y g:i A'),
                    'needs_attention' => $inspector->accountNeedsAttention($account),
                ],
            ]);

        return view('livewire.social-accounts.index', [
            'accounts' => $accounts,
            'linkedInStatuses' => $linkedInStatuses,
        ]);
    }
}
