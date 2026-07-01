<?php

namespace App\Livewire\Posts\Concerns;

use App\Domain\SocialAccounts\LinkedInTokenInspector;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

trait RedirectsForLinkedInReauthorization
{
    /**
     * @param  array<int>  $selectedAccountIds
     */
    protected function finishPostSave(
        string $label,
        array $selectedAccountIds,
        string $scheduledFor,
        ?string $returnTo = null,
        bool $completeWithRedirect = true,
    ): void {
        $this->flashSuccess("Post {$label} successfully.");

        $this->attemptLinkedInReauthorizationRedirect(
            $selectedAccountIds,
            $scheduledFor,
            $returnTo ?? route('dashboard'),
            completeWithRedirect: $completeWithRedirect,
        );
    }

    protected function flashSuccess(string $message): void
    {
        session()->flash('message', $message);
    }

    protected function flashWarning(string $message): void
    {
        session()->flash('warning', $message);
    }

    /**
     * @param  array<int>  $selectedAccountIds
     */
    protected function attemptLinkedInReauthorizationRedirect(
        array $selectedAccountIds,
        string $scheduledFor,
        string $returnTo,
        bool $completeWithRedirect = false,
    ): void {
        if ($scheduledFor === '') {
            if ($completeWithRedirect) {
                $this->redirect($returnTo, navigate: true);
            }

            return;
        }

        $inspector = app(LinkedInTokenInspector::class);
        $scheduledAt = Carbon::parse($scheduledFor);
        $user = Auth::user();

        $ownedAccount = $inspector->firstOwnedAccountNeedingReauthorization(
            $selectedAccountIds,
            $scheduledAt,
            $user,
        );

        if ($ownedAccount !== null) {
            session()->put('linkedin_oauth_return_to', $returnTo);

            $this->redirect(route('social-accounts.linkedin-oauth-redirect'), navigate: false);

            return;
        }

        $sharedAccounts = $inspector->accountsNeedingReauthorization($selectedAccountIds, $scheduledAt)
            ->reject(fn ($account) => $account->user_id === $user->id);

        if ($sharedAccounts->isNotEmpty()) {
            $names = $sharedAccounts->pluck('display_name')->join(', ');
            $this->flashWarning(
                "The LinkedIn account(s) {$names} need reauthorization before the scheduled publish time. Ask the account owner to reconnect.",
            );
        }

        if ($completeWithRedirect) {
            $this->redirect($returnTo, navigate: true);
        }
    }
}
