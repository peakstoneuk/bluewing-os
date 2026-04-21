<?php

use App\Livewire\SocialAccounts\ConnectLinkedIn;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('connect linkedin page requires authentication', function () {
    $this->get(route('social-accounts.connect-linkedin'))
        ->assertRedirect(route('login'));
});

test('connect linkedin page shows oauth button when configured', function () {
    config([
        'services.linkedin.client_id' => 'linkedin-id',
        'services.linkedin.client_secret' => 'linkedin-secret',
    ]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(ConnectLinkedIn::class)
        ->assertSee('Connect with LinkedIn')
        ->assertDontSee('Configuration Required');
});

test('connect linkedin page shows configuration warning when not configured', function () {
    config([
        'services.linkedin.client_id' => null,
        'services.linkedin.client_secret' => null,
    ]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(ConnectLinkedIn::class)
        ->assertSee('Configuration Required')
        ->assertSee('LINKEDIN_CLIENT_ID')
        ->assertDontSee('Connect with LinkedIn');
});
