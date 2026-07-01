<?php

namespace App\Livewire\Posts;

use App\Domain\Posts\ListPostsQuery;
use App\Domain\SocialAccounts\GetAccessibleAccountsQuery;
use App\Enums\Provider;
use App\Models\Post;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Calendar')]
class Calendar extends Component
{
    public const PREVIEW_LIMIT = 3;

    public int $year;

    public int $month;

    #[Url(as: 'provider')]
    public string $filterProvider = '';

    #[Url(as: 'account')]
    public string $filterAccount = '';

    public function mount(): void
    {
        $now = now(Auth::user()->timezone());

        $this->year = $now->year;
        $this->month = $now->month;
    }

    public function updatedFilterProvider(): void
    {
        $this->filterAccount = '';
    }

    public function clearFilters(): void
    {
        $this->filterProvider = '';
        $this->filterAccount = '';
    }

    public function previousMonth(): void
    {
        $date = $this->monthAnchor()->subMonth();
        $this->year = $date->year;
        $this->month = $date->month;
    }

    public function nextMonth(): void
    {
        $date = $this->monthAnchor()->addMonth();
        $this->year = $date->year;
        $this->month = $date->month;
    }

    public function goToToday(): void
    {
        $now = now(Auth::user()->timezone());

        $this->year = $now->year;
        $this->month = $now->month;
    }

    #[Computed]
    public function monthLabel(): string
    {
        return $this->monthAnchor()->format('F Y');
    }

    #[Computed]
    public function providers(): array
    {
        return Provider::cases();
    }

    #[Computed]
    public function accessibleAccounts(): Collection
    {
        $query = new GetAccessibleAccountsQuery(Auth::user());

        if ($this->filterProvider !== '') {
            $query->provider($this->filterProvider);
        }

        return $query->get();
    }

    #[Computed]
    public function hasActiveFilters(): bool
    {
        return $this->filterProvider !== '' || $this->filterAccount !== '';
    }

    #[Computed]
    public function calendarWeeks(): array
    {
        $timezone = Auth::user()->timezone();
        $today = now($timezone);

        $start = $this->monthAnchor()->copy()->startOfWeek(Carbon::SUNDAY);
        $end = $this->monthAnchor()->copy()->endOfMonth()->endOfWeek(Carbon::SATURDAY);

        $listQuery = (new ListPostsQuery(Auth::user()))
            ->from($start->copy()->utc()->toDateTimeString())
            ->to($end->copy()->endOfDay()->utc()->toDateTimeString())
            ->provider($this->filterProvider ?: null)
            ->account($this->filterAccount ?: null);

        $posts = $listQuery
            ->query()
            ->orderBy('scheduled_for')
            ->get()
            ->groupBy(fn (Post $post) => $post->scheduled_for->copy()->timezone($timezone)->format('Y-m-d'));

        $weeks = [];
        $current = $start->copy();

        while ($current <= $end) {
            $week = [];
            for ($i = 0; $i < 7; $i++) {
                $dateKey = $current->format('Y-m-d');
                $week[] = [
                    'date' => $current->copy(),
                    'isCurrentMonth' => $current->month === $this->month,
                    'isToday' => $current->isSameDay($today),
                    'posts' => $posts->get($dateKey, collect()),
                ];
                $current->addDay();
            }
            $weeks[] = $week;
        }

        return $weeks;
    }

    /**
     * Build a compact target summary for a post, suitable for calendar chips.
     *
     * @return array{preview: array<int, array{provider: string, provider_label: string, display_name: string, social_account_id: int}>, total: int, overflow: int}
     */
    public static function targetsSummary(Post $post, int $limit = self::PREVIEW_LIMIT): array
    {
        $targets = $post->targets->map(fn ($target) => [
            'provider' => $target->socialAccount->provider->value,
            'provider_label' => $target->socialAccount->provider->label(),
            'display_name' => $target->socialAccount->display_name,
            'social_account_id' => $target->social_account_id,
        ]);

        $total = $targets->count();

        return [
            'preview' => $targets->take($limit)->values()->all(),
            'total' => $total,
            'overflow' => max(0, $total - $limit),
        ];
    }

    protected function monthAnchor(): Carbon
    {
        return Carbon::create($this->year, $this->month, 1, 0, 0, 0, Auth::user()->timezone());
    }

    public function render()
    {
        return view('livewire.posts.calendar');
    }
}
