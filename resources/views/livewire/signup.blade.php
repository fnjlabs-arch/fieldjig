<?php

use App\Core\Tenancy\Services\SignupService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.auth')] #[Title('Sign up')] class extends Component {
    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $company_name = '';

    public function signup(SignupService $service): void
    {
        $result = $service->register([
            'name' => $this->name,
            'email' => $this->email,
            'password' => $this->password,
            'password_confirmation' => $this->password_confirmation,
            'company_name' => $this->company_name,
        ]);

        Auth::login($result['user']);

        $this->redirect(route('dashboard'));
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-auth-header :title="__('Create your SadSha Build account')" :description="__('Start your 30-day free trial on one project — no card required.')" />

    <form wire:submit="signup" class="flex flex-col gap-6">
        <flux:input
            wire:model="name"
            :label="__('Your name')"
            type="text"
            required
            autofocus
            autocomplete="name"
            :placeholder="__('Jane Builder')"
        />

        <flux:input
            wire:model="email"
            :label="__('Email')"
            type="email"
            required
            autocomplete="email"
            :placeholder="__('email@example.com')"
        />

        <flux:input
            wire:model="company_name"
            :label="__('Company name')"
            type="text"
            required
            autocomplete="organization"
            :placeholder="__('Acme Builders')"
        />

        <flux:input
            wire:model="password"
            :label="__('Password')"
            type="password"
            required
            autocomplete="new-password"
        />

        <flux:input
            wire:model="password_confirmation"
            :label="__('Confirm password')"
            type="password"
            required
            autocomplete="new-password"
        />

        <flux:button type="submit" variant="primary" class="w-full">
            {{ __('Create account & start trial') }}
        </flux:button>
    </form>

    <p class="text-center text-sm text-zinc-500">
        {{ __('Already have an account?') }}
        <a href="{{ route('login') }}" class="font-medium text-zinc-800 underline">{{ __('Log in') }}</a>
    </p>
</div>
