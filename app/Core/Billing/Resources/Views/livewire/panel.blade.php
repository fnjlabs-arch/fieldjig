<div class="flex flex-col gap-6">
    <flux:heading size="xl">{{ __('Billing') }}</flux:heading>

    @if (session('status'))
        <div class="rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('status') }}</div>
    @endif

    @if ($errors->has('billing'))
        <div class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800">{{ $errors->first('billing') }}</div>
    @endif

    @if (! $saasEnabled)
        <div class="rounded-lg bg-zinc-50 px-4 py-3 text-sm text-zinc-700">
            {{ __('SaaS billing is not enabled on this install (SAAS_MODE is off).') }}
        </div>
    @elseif ($organizationName === null)
        <div class="rounded-lg bg-zinc-50 px-4 py-3 text-sm text-zinc-700">
            {{ __('No organization selected. Create an organization first, then manage billing here.') }}
        </div>
    @else
        <div class="rounded-lg border border-zinc-200 px-4 py-4">
            <dl class="grid grid-cols-2 gap-4 text-sm">
                <div>
                    <dt class="text-zinc-500">{{ __('Organization') }}</dt>
                    <dd class="font-medium">{{ $organizationName }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">{{ __('Plan') }}</dt>
                    <dd class="font-medium">{{ $plan === 'paid' ? __('Paid') : __('Trial') }} — ${{ $priceMonthly }}/{{ __('month') }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">{{ __('Status') }}</dt>
                    <dd class="font-medium">{{ $status }}</dd>
                </div>
                @if ($trialDaysLeft !== null)
                    <div>
                        <dt class="text-zinc-500">{{ __('Trial days left') }}</dt>
                        <dd class="font-medium">{{ $trialDaysLeft }} ({{ __('ends') }} {{ $trialEndsAt }})</dd>
                    </div>
                @endif
            </dl>
        </div>

        @if ($status === 'active')
            <div class="rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800">
                {{ __('Your subscription is active. Thank you!') }}
            </div>
        @elseif ($status === 'past_due')
            <div class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800">
                {{ __('Your subscription is past due. You have a 7-day grace period — upgrade below to restore full access. No new projects can be created while past due.') }}
            </div>
        @elseif ($status === 'canceled')
            <div class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800">
                {{ __('Your subscription was canceled. Upgrade below to reactivate it.') }}
            </div>
        @endif

        @if ($status !== 'active')
            <form method="POST" action="{{ route('billing.checkout') }}" class="flex flex-col gap-2">
                @csrf
                <flux:button type="submit" variant="primary" :disabled="! $billingConfigured">
                    {{ __('Upgrade to Paid — $:price/month', ['price' => $priceMonthly]) }}
                </flux:button>
                @if (! $billingConfigured)
                    <p class="text-sm text-zinc-500">
                        {{ __('Billing is not configured yet — the Lemon Squeezy variant has not been set up. Please contact the platform operator.') }}
                    </p>
                @endif
            </form>
        @endif

        @if ($hasSubscription && in_array($status, ['active', 'past_due'], true))
            <form method="POST" action="{{ route('billing.cancel') }}" onsubmit="return confirm('{{ __('Cancel your subscription?') }}')">
                @csrf
                <flux:button type="submit" variant="ghost">{{ __('Cancel subscription') }}</flux:button>
            </form>
        @endif
    @endif
</div>
