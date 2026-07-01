<?php

namespace App\Domain\Posts;

use App\Enums\Provider;
use App\Models\SocialAccount;
use App\Services\SocialProviders\Bluesky\BlueskyTextLimits;

class ValidatePostTextForTargetsAction
{
    /**
     * Validate post text against provider-specific limits for the selected targets.
     *
     * Only the text that would actually be published to each Bluesky target is
     * checked, using the same precedence as Post::resolveTextForTarget:
     * account override > provider override > default.
     *
     * @return array<string, string[]> Field-keyed validation errors.
     */
    public function execute(PostData $data): array
    {
        $blueskyAccounts = SocialAccount::query()
            ->whereIn('id', $data->targetAccountIds)
            ->where('provider', Provider::Bluesky)
            ->get();

        if ($blueskyAccounts->isEmpty()) {
            return [];
        }

        $errors = [];

        foreach ($blueskyAccounts as $account) {
            [$text, $field] = $this->resolveTextAndField($data, $account);

            if (BlueskyTextLimits::exceedsLimit($text)) {
                $errors[$field][] = BlueskyTextLimits::errorMessage($text);
            }
        }

        return array_map(
            fn (array $messages) => array_values(array_unique($messages)),
            $errors,
        );
    }

    /**
     * @return array{0: string, 1: string} The resolved text and validation field name.
     */
    protected function resolveTextAndField(PostData $data, SocialAccount $account): array
    {
        $accountOverride = $data->accountOverrides[$account->id] ?? null;

        if (is_string($accountOverride) && trim($accountOverride) !== '') {
            return [$accountOverride, "account_overrides.{$account->id}"];
        }

        $providerOverride = $data->providerOverrides[Provider::Bluesky->value] ?? null;

        if (is_string($providerOverride) && trim($providerOverride) !== '') {
            return [$providerOverride, 'provider_overrides.bluesky'];
        }

        return [$data->bodyText, 'body_text'];
    }
}
