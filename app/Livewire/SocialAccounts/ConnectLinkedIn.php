<?php

namespace App\Livewire\SocialAccounts;

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Connect LinkedIn Account')]
class ConnectLinkedIn extends Component
{
    public function render()
    {
        return view('livewire.social-accounts.connect-linkedin', [
            'isConfigured' => ! empty(config('services.linkedin.client_id')) && ! empty(config('services.linkedin.client_secret')),
        ]);
    }
}
