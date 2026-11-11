<?php

namespace App\Core\Billing\Livewire;

use App\Core\Billing\Services\BillingManager;
use App\Core\Tenancy\Models\Organization;
use App\Core\Tenancy\Services\TenantContext;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Billing panel (§2, Livewire-first). Shows the current org's plan,
 * status, trial days left and the $99/mo price; the upgrade button
 * posts to POST /billing/checkout and is DISABLED with a clear
 * "billing not configured yet" state when the Lemon Squeezy variant
 * env is absent; cancel posts to POST /billing/cancel.
 */
#[Layout('layouts.app')]
#[Title('Billing')]
class Panel extends Component
{
    public ?string $organizationName = null;

    public ?string $plan = null;

    public ?string $status = null;

    public ?int $trialDaysLeft = null;

    public ?string $trialEndsAt = null;

    public int $priceMonthly = 99;

    public bool $billingConfigured = false;

    public bool $hasSubscription = false;

    public bool $saasEnabled = false;

    public function mount(TenantContext $context, BillingManager $billing): void
    {
        $this->saasEnabled = $context->isEnabled();
        $this->priceMonthly = (int) config('saas.price_monthly', 99);
        $this->billingConfigured = $billing->provider()->isConfigured();

        $organization = $context->currentOrganization();

        if (! $organization instanceof Organization) {
            return;
        }

        $this->organizationName = $organization->name;
        $this->plan = $organization->plan;
        $this->status = $organization->status;
        $this->hasSubscription = $organization->billing_subscription_id !== null;
        $this->trialEndsAt = $organization->trial_ends_at?->toDateString();

        if ($organization->status === Organization::STATUS_TRIALING && $organization->trial_ends_at !== null) {
            $this->trialDaysLeft = max(0, (int) now()->startOfDay()->diffInDays($organization->trial_ends_at->startOfDay(), false));
        }
    }

    public function render(): View
    {
        return view('billing::livewire.panel');
    }
}
