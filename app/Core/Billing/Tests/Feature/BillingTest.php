<?php

use App\Core\Billing\Contracts\BillingProvider;
use App\Core\Billing\Models\BillingWebhookEvent;
use App\Core\Billing\Providers\LemonSqueezyProvider;
use App\Core\Billing\Providers\PaddleProvider;
use App\Core\Tenancy\Models\Organization;
use App\Core\Tenancy\Models\OrganizationMember;
use App\Core\Tenancy\Services\TenantContext;
use App\Core\Tenancy\Services\TenantProjectCreation;
use App\Core\Identity\Models\User;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    config()->set('saas.mode', true);
    config()->set('billing.provider', 'lemonsqueezy');
    config()->set('billing.lemonsqueezy.webhook_secret', 'test-webhook-secret');
    config()->set('billing.lemonsqueezy.api_key', '');
    config()->set('billing.lemonsqueezy.store_id', '');
    config()->set('billing.lemonsqueezy.variant_pro', '');
    app(TenantContext::class)->clear();
});

function billingOrgOwner(?Organization $organization = null): array
{
    $organization ??= Organization::factory()->create();

    $user = User::factory()->create(['is_admin' => false]);

    OrganizationMember::query()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'role' => OrganizationMember::ROLE_OWNER,
    ]);

    return [$user, $organization];
}

/**
 * A Lemon Squeezy webhook body for the given org + event, with the
 * subscription attributes a real delivery carries.
 *
 * @param  array<string, mixed>  $attributes
 */
function lemonWebhookBody(Organization $organization, string $eventName, array $attributes = []): string
{
    return json_encode([
        'meta' => [
            'event_name' => $eventName,
            'custom_data' => ['organization_id' => $organization->id],
        ],
        'data' => [
            'type' => 'subscriptions',
            'id' => 'sub_test_123',
            'attributes' => array_merge([
                'store_id' => 1,
                'customer_id' => 456,
                'status' => 'active',
            ], $attributes),
        ],
    ], JSON_THROW_ON_ERROR);
}

function lemonSignature(string $body): string
{
    return hash_hmac('sha256', $body, 'test-webhook-secret');
}

function postLemonWebhook(object $testCase, string $body, ?string $signature = null, ?string $eventName = null)
{
    $headers = [];

    if ($signature !== null) {
        $headers['X-Signature'] = $signature;
    }

    if ($eventName !== null) {
        $headers['X-Event-Name'] = $eventName;
    }

    return $testCase->postJson(
        route('billing.webhook.lemonsqueezy'),
        json_decode($body, true, 512, JSON_THROW_ON_ERROR),
        $headers,
    );
}

it('verifies the webhook signature against a known hmac vector', function (): void {
    // Precomputed: hash_hmac('sha256', '{"data":{"type":"subscriptions"}}', 'test-secret-123').
    expect(hash_hmac('sha256', '{"data":{"type":"subscriptions"}}', 'test-secret-123'))
        ->toBe('02e4d25718be28d917b9a0d955fef04a0b7f2979fc19543254f66f4f7f79bc23');

    [$user, $organization] = billingOrgOwner();

    $body = lemonWebhookBody($organization, 'subscription_created');

    // Correct signature, computed over the raw body.
    postLemonWebhook($this, $body, lemonSignature($body), 'subscription_created')
        ->assertOk()
        ->assertJsonPath('events', ['subscription_created']);

    // Wrong signature.
    postLemonWebhook($this, $body, str_repeat('0', 64), 'subscription_created')
        ->assertUnauthorized();

    // Right signature, tampered body.
    $tampered = str_replace('"active"', '"cancelled"', $body);

    postLemonWebhook($this, $tampered, lemonSignature($body), 'subscription_created')
        ->assertUnauthorized();

    // Missing signature.
    postLemonWebhook($this, $body, null, 'subscription_created')
        ->assertUnauthorized();
});

it('applies subscription_created and records the billing ids', function (): void {
    [$user, $organization] = billingOrgOwner();

    $body = lemonWebhookBody($organization, 'subscription_created', ['status' => 'active']);

    postLemonWebhook($this, $body, lemonSignature($body), 'subscription_created')->assertOk();

    $organization->refresh();

    expect($organization->plan)->toBe(Organization::PLAN_PAID)
        ->and($organization->status)->toBe(Organization::STATUS_ACTIVE)
        ->and($organization->billing_provider)->toBe('lemonsqueezy')
        ->and($organization->billing_customer_id)->toBe('456')
        ->and($organization->billing_subscription_id)->toBe('sub_test_123');
});

it('maps subscription_updated past_due and cancelled states', function (): void {
    [$user, $organization] = billingOrgOwner();
    $organization->update(['plan' => Organization::PLAN_PAID, 'status' => Organization::STATUS_ACTIVE]);

    $pastDueBody = lemonWebhookBody($organization, 'subscription_updated', ['status' => 'past_due']);

    postLemonWebhook($this, $pastDueBody, lemonSignature($pastDueBody), 'subscription_updated')->assertOk();

    $organization->refresh();

    expect($organization->status)->toBe(Organization::STATUS_PAST_DUE)
        ->and($organization->past_due_at)->not->toBeNull();

    $cancelledBody = lemonWebhookBody($organization, 'subscription_cancelled', ['status' => 'cancelled']);

    postLemonWebhook($this, $cancelledBody, lemonSignature($cancelledBody), 'subscription_cancelled')->assertOk();

    $organization->refresh();

    expect($organization->status)->toBe(Organization::STATUS_CANCELED);
});

it('skips an idempotent replay of the same delivery', function (): void {
    [$user, $organization] = billingOrgOwner();

    $body = lemonWebhookBody($organization, 'subscription_created', ['status' => 'active']);

    postLemonWebhook($this, $body, lemonSignature($body), 'subscription_created')
        ->assertOk()
        ->assertJsonPath('events', ['subscription_created']);

    postLemonWebhook($this, $body, lemonSignature($body), 'subscription_created')
        ->assertOk()
        ->assertJsonPath('events', []);

    expect(BillingWebhookEvent::query()->count())->toBe(1);
});

it('records the customer id from order_created without changing plan or status', function (): void {
    [$user, $organization] = billingOrgOwner();

    $body = json_encode([
        'meta' => [
            'event_name' => 'order_created',
            'custom_data' => ['organization_id' => $organization->id],
        ],
        'data' => [
            'type' => 'orders',
            'id' => 'order_1',
            'attributes' => ['customer_id' => 789, 'status' => 'paid'],
        ],
    ], JSON_THROW_ON_ERROR);

    postLemonWebhook($this, $body, lemonSignature($body), 'order_created')->assertOk();

    $organization->refresh();

    expect($organization->billing_customer_id)->toBe('789')
        ->and($organization->plan)->toBe(Organization::PLAN_TRIAL)
        ->and($organization->status)->toBe(Organization::STATUS_TRIALING);
});

it('accepts a webhook it cannot match to an organization', function (): void {
    $body = json_encode([
        'meta' => ['event_name' => 'subscription_created', 'custom_data' => []],
        'data' => [
            'type' => 'subscriptions',
            'id' => 'sub_unknown',
            'attributes' => ['customer_id' => 999999, 'status' => 'active'],
        ],
    ], JSON_THROW_ON_ERROR);

    // 200 (Lemon retries non-2xx): the delivery is recorded, nothing crashes.
    postLemonWebhook($this, $body, lemonSignature($body), 'subscription_created')->assertOk();
});

it('shows the billing panel disabled when the variant is not configured', function (): void {
    [$user, $organization] = billingOrgOwner();

    $this->actingAs($user)
        ->get(route('billing.panel'))
        ->assertOk()
        ->assertSee('Billing is not configured yet')
        ->assertSee((string) $organization->trial_ends_at->toDateString());
});

it('shows the upgrade button enabled when the provider is configured', function (): void {
    [$user, $organization] = billingOrgOwner();

    app()->instance(BillingProvider::class, new FakeBillingProvider(configured: true));

    $this->actingAs($user)
        ->get(route('billing.panel'))
        ->assertOk()
        ->assertSee('Upgrade to Paid')
        ->assertDontSee('Billing is not configured yet');
});

it('redirects checkout to the provider url through the provider contract', function (): void {
    [$user, $organization] = billingOrgOwner();

    $fake = new FakeBillingProvider(configured: true, checkoutUrl: 'https://lemonsqueezy.test/checkout/abc');
    app()->instance(BillingProvider::class, $fake);

    $this->actingAs($user)
        ->post(route('billing.checkout'))
        ->assertRedirect('https://lemonsqueezy.test/checkout/abc');
});

it('refuses checkout when billing is not configured', function (): void {
    [$user, $organization] = billingOrgOwner();

    // Real provider, empty credentials: no live call is attempted.
    $this->actingAs($user)
        ->post(route('billing.checkout'))
        ->assertRedirect(route('billing.panel'));
});

it('cancels through the provider contract', function (): void {
    [$user, $organization] = billingOrgOwner();
    $organization->update([
        'plan' => Organization::PLAN_PAID,
        'status' => Organization::STATUS_ACTIVE,
        'billing_subscription_id' => 'sub_test_123',
    ]);

    $fake = new FakeBillingProvider(configured: true);
    app()->instance(BillingProvider::class, $fake);

    $this->actingAs($user)
        ->post(route('billing.cancel'))
        ->assertRedirect(route('billing.panel'));

    expect($fake->cancelledOrganizationIds)->toContain($organization->id);
});

it('creates a lemon checkout through the api with the org in custom data', function (): void {
    config()->set('billing.lemonsqueezy.api_key', 'test-api-key');
    config()->set('billing.lemonsqueezy.store_id', '111');
    config()->set('billing.lemonsqueezy.variant_pro', '222');

    Http::fake([
        'api.lemonsqueezy.com/*' => Http::response([
            'data' => [
                'type' => 'checkouts',
                'id' => 'checkout_1',
                'attributes' => ['url' => 'https://app.lemonsqueezy.com/checkout/buy/xyz'],
            ],
        ], 201),
    ]);

    [$user, $organization] = billingOrgOwner();

    $url = app(LemonSqueezyProvider::class)->createCheckout($organization, Organization::PLAN_PAID);

    expect($url)->toBe('https://app.lemonsqueezy.com/checkout/buy/xyz');

    Http::assertSent(function ($request) use ($organization): bool {
        $data = $request->data();

        return $request->url() === 'https://api.lemonsqueezy.com/v1/checkouts'
            && $data['data']['relationships']['variant']['data']['id'] === '222'
            && $data['data']['attributes']['checkout_data']['custom']['organization_id'] === $organization->id;
    });
});

it('expires a trial lazily to past_due on the next request', function (): void {
    $organization = Organization::factory()->create(['trial_ends_at' => now()->subDay()]);
    [$user] = billingOrgOwner($organization);

    $this->actingAs($user)->get(route('billing.panel'))->assertOk();

    expect($organization->fresh()->status)->toBe(Organization::STATUS_PAST_DUE)
        ->and($organization->fresh()->past_due_at)->not->toBeNull();
});

it('moves a past_due org to canceled after the 7-day grace and blocks app access', function (): void {
    $organization = Organization::factory()->create([
        'status' => Organization::STATUS_PAST_DUE,
        'past_due_at' => now()->subDays(8),
    ]);
    [$user] = billingOrgOwner($organization);

    $this->actingAs($user)->get(route('billing.panel'))->assertOk();

    expect($organization->fresh()->status)->toBe(Organization::STATUS_CANCELED);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('billing.panel'));
});

it('blocks project creation for past_due and canceled orgs', function (): void {
    $pastDue = Organization::factory()->create(['status' => Organization::STATUS_PAST_DUE]);
    [$user] = billingOrgOwner($pastDue);
    app(TenantContext::class)->setOrganizationIds([$pastDue->id], $pastDue->id);

    try {
        app(TenantProjectCreation::class)->create($user, ['name' => 'Nope']);
        $this->fail('Expected a 403 for a past_due org.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(403)
            ->and($exception->getMessage())->toContain('past due');
    }

    $canceled = Organization::factory()->create(['status' => Organization::STATUS_CANCELED]);
    app(TenantContext::class)->setOrganizationIds([$canceled->id], $canceled->id);

    try {
        app(TenantProjectCreation::class)->create($user, ['name' => 'Nope']);
        $this->fail('Expected a 403 for a canceled org.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(403)
            ->and($exception->getMessage())->toContain('canceled');
    }
});

it('throws not built yet for the paddle stub', function (): void {
    $organization = Organization::factory()->create();
    $provider = new PaddleProvider();

    expect($provider->isConfigured())->toBeFalse();

    try {
        $provider->createCheckout($organization, Organization::PLAN_PAID);
        $this->fail('Expected the Paddle stub to throw.');
    } catch (LogicException $exception) {
        expect($exception->getMessage())->toContain('not built yet');
    }
});

/**
 * In-test fake for the BillingProvider contract (no live API calls).
 */
class FakeBillingProvider implements BillingProvider
{
    /** @var array<int, string> */
    public array $cancelledOrganizationIds = [];

    public function __construct(
        private readonly bool $configured = true,
        private readonly string $checkoutUrl = 'https://example.test/checkout/fake',
    ) {}

    public function createCheckout(Organization $organization, string $plan): string
    {
        return $this->checkoutUrl;
    }

    /**
     * @return array<int, string>
     */
    public function handleWebhook(string $rawPayload, ?string $signature, ?string $eventName = null): array
    {
        return [$eventName ?? 'fake-event'];
    }

    public function portalUrl(Organization $organization): ?string
    {
        return null;
    }

    public function cancelSubscription(Organization $organization): void
    {
        $this->cancelledOrganizationIds[] = $organization->id;
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }
}
