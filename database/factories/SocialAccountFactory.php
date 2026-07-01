<?php

namespace Database\Factories;

use App\Enums\Provider;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SocialAccount>
 */
class SocialAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => fake()->randomElement(Provider::cases()),
            'display_name' => '@'.fake()->userName(),
            'external_identifier' => (string) fake()->unique()->randomNumber(9),
            'credentials_encrypted' => ['token' => 'test-token'],
        ];
    }

    public function x(): static
    {
        return $this->state(fn () => [
            'provider' => Provider::X,
        ]);
    }

    public function bluesky(): static
    {
        return $this->state(fn () => [
            'provider' => Provider::Bluesky,
        ]);
    }

    public function linkedin(): static
    {
        return $this->state(fn () => [
            'provider' => Provider::LinkedIn,
            'credentials_encrypted' => [
                'access_token' => 'linkedin-access-token',
                'refresh_token' => null,
                'expires_at' => now()->addDays(30)->toIso8601String(),
            ],
        ]);
    }

    public function linkedinWithRefreshToken(): static
    {
        return $this->state(fn () => [
            'provider' => Provider::LinkedIn,
            'credentials_encrypted' => [
                'access_token' => 'linkedin-access-token',
                'refresh_token' => 'linkedin-refresh-token',
                'expires_at' => now()->addDays(30)->toIso8601String(),
            ],
        ]);
    }

    public function linkedinExpired(): static
    {
        return $this->state(fn () => [
            'provider' => Provider::LinkedIn,
            'credentials_encrypted' => [
                'access_token' => 'linkedin-access-token',
                'refresh_token' => null,
                'expires_at' => now()->subDay()->toIso8601String(),
            ],
        ]);
    }
}
