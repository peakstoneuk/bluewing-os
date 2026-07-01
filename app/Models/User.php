<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\UserTimezone;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'timezone',
        'password',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn ($word) => Str::substr($word, 0, 1))
            ->implode('');
    }

    public function timezone(): string
    {
        return UserTimezone::normalize($this->timezone);
    }

    public function timezoneLabel(): string
    {
        return UserTimezone::label($this->timezone());
    }

    public function formatDateTime(?CarbonInterface $datetime, string $format = 'M j, Y g:i A'): string
    {
        if ($datetime === null) {
            return '';
        }

        return UserTimezone::format($datetime, $this->timezone(), $format);
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function socialAccountPermissions(): HasMany
    {
        return $this->hasMany(SocialAccountPermission::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /**
     * All social accounts this user can access - owned plus those shared with them.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, SocialAccount>
     */
    public function accessibleSocialAccounts(): \Illuminate\Database\Eloquent\Collection
    {
        $ownedIds = $this->socialAccounts()->pluck('id');
        $sharedIds = $this->socialAccountPermissions()->pluck('social_account_id');

        return SocialAccount::whereIn('id', $ownedIds->merge($sharedIds)->unique())
            ->get();
    }
}
