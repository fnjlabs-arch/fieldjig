<?php

use App\Core\Billing\Exceptions\BillingNotConfiguredException;
use App\Core\Billing\Models\BillingWebhookEvent;
use App\Core\Billing\Providers\PaddleProvider;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Organization;
use App\Core\Tenancy\Models\OrganizationMember;
use App\Core\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('saas.mode', true);
    config()->set('billing.provider', 'paddle');
    config()->set('billing.paddle.api_key', 'test-api-key');
    config()->set('billing.paddle.price_pro', 'pri_test_123');
    config()->set('billing.paddle.webhook_secret', 'test-webhook-secret');
    app(TenantContext::class)->clear();
});

function paddleBillingOrgOwner(?Organization $organization = null): array
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
 * A Paddle webhook body for the given event, with the subscription /
 * transaction data a real delivery carries.
 *
 * @param  array<string, mixed>  $data
 */
function paddleWebhookBody(string $eventType, array $data, string $eventId = 'evt_test_123'): string
{
    return json_encode([
        'event_id' => $eventId,
        'event_type' => $eventType,
        'occurred_at' => '2026-11-23T12:00:00.000Z',
        'notification_id' => 'ntf_test_1',
        'data' => $data,
    ], JSON_THROW_ON_ERROR);
}

/**
 * A Paddle subscription event body for the given org.
 *
 * @param  array<string, mixed>  $attributes
 */
function paddleSubscriptionBody(Organization $organization, string $eventType, array $attributes = [], string $eventId = 'evt_test_123'): string
{
    return paddleWebhookBody($eventType, array_merge([
        'id' => 'sub_test_123',
        'customer_id' => 'ctm_test_456',
        'status' => 'active',
        'custom_data' => ['organization_id' => (string) $organization->id],
    ], $attributes), $eventId);
}

function paddleSignature(string $body, ?int $timestamp = null): string
{
    $timestamp ??= time();

    return 'ts='.$timestamp.';h1='.hash_hmac('sha256', $timestamp.':'.$body, 'test-webhook-secret');
}

function postPaddleWebhook(object $testCase, string $body, ?string $signature = null)
{
    $headers = [];

    if ($signature !== null) {
        $headers['Paddle-Signature'] = $signature;
    }

    return $testCase->postJson(
        route('billing.webhook.paddle'),
        json_decode($body, true, 512, JSON_THROW_ON_ERROR),
        $headers,
    );
}

it('verifies the webhook signature against a known hmac vector', function (): void {
    // Precomputed: hash_hmac('sha256', '1763899200:{"data":{"type":"subscriptions"}}', 'test-secret-123').
    expect(hash_hmac('sha256', '1763899200:{"data":{"type":"subscriptions"}}', 'test-secret-123'))
        ->toBe('9285d7b56d8f3155305b0bf6b2d60282fbd1212780e66fa5514bd798ac2bd715');

    [$user, $organization] = paddleBillingOrgOwner();

    $body = paddleSubscriptionBody($organization, 'subscription.created');

    // Correct signature, computed over the raw body.
    postPaddleWebhook($this, $body, paddleSignature($body))
        ->assertOk()
        ->assertJsonPath('events', ['subscription.created']);

    // Wrong signature.
    postPaddleWebhook($this, $body, 'ts='.time().';h1='.str_repeat('0', 64))
        ->assertUnauthorized();

    // Right signature, tampered body.
    $tampered = str_replace('"active"', '"canceled"', $body);

    postPaddleWebhook($this, $tampered, paddleSignature($body))
        ->assertUnauthorized();

    // Missing signature.
    postPaddleWebhook($this, $body, null)
        ->assertUnauthorized();

    // Stale timestamp (more than 300s old).
    postPaddleWebhook($this, $body, paddleSignature($body, time() - 301))
        ->assertUnauthorized();

    // Future timestamp (more than 300s ahead).
    postPaddleWebhook($this, $body, paddleSignature($body, time() + 301))
        ->assertUnauthorized();
});

it('applies subscription.created and records the billing ids', function (): void {
    [$user, $organization] = paddleBillingOrgOwner();

    $body = paddleSubscriptionBody($organization, 'subscription.created', ['status' => 'active']);

    postPaddleWebhook($this, $body, paddleSignature($body))->assertOk();

    $organization->refresh();

    expect($organization->plan)->toBe(Organization::PLAN_PAID)
        ->and($organization->status)->toBe(Organization::STATUS_ACTIVE)
        ->and($organization->billing_provider)->toBe('paddle')
        ->and($organization->billing_customer_id)->toBe('ctm_test_456')
        ->and($organization->billing_subscription_id)->toBe('sub_test_123');
});

it('maps subscription.updated past_due and canceled states', function (): void {
    [$user, $organization] = paddleBillingOrgOwner();
    $organization->update(['plan' => Organization::PLAN_PAID, 'status' => Organization::STATUS_ACTIVE]);

    $pastDueBody = paddleSubscriptionBody($organization, 'subscription.updated', ['status' => 'past_due'], 'evt_test_past_due');

    postPaddleWebhook($this, $pastDueBody, paddleSignature($pastDueBody))->assertOk();

    $organization->refresh();

    expect($organization->status)->toBe(Organization::STATUS_PAST_DUE)
        ->and($organization->past_due_at)->not->toBeNull();

    $cancelledBody = paddleSubscriptionBody($organization, 'subscription.canceled', ['status' => 'canceled'], 'evt_test_canceled');

    postPaddleWebhook($this, $cancelledBody, paddleSignature($cancelledBody))->assertOk();

    $organization->refresh();

    expect($organization->status)->toBe(Organization::STATUS_CANCELED);
});

it('maps a paused subscription to past_due and resumes it to active', function (): void {
    [$user, $organization] = paddleBillingOrgOwner();
    $organization->update(['plan' => Organization::PLAN_PAID, 'status' => Organization::STATUS_ACTIVE]);

    $pausedBody = paddleSubscriptionBody($organization, 'subscription.paused', ['status' => 'paused'], 'evt_test_paused');

    postPaddleWebhook($this, $pausedBody, paddleSignature($pausedBody))->assertOk();

    $organization->refresh();

    // The app has no paused state: paused maps to past_due.
    expect($organization->status)->toBe(Organization::STATUS_PAST_DUE);

    $resumedBody = paddleSubscriptionBody($organization, 'subscription.resumed', ['status' => 'active'], 'evt_test_resumed');

    postPaddleWebhook($this, $resumedBody, paddleSignature($resumedBody))->assertOk();

    $organization->refresh();

    expect($organization->status)->toBe(Organization::STATUS_ACTIVE)
        ->and($organization->past_due_at)->toBeNull();
});

it('skips an idempotent replay of the same delivery', function (): void {
    [$user, $organization] = paddleBillingOrgOwner();

    $body = paddleSubscriptionBody($organization, 'subscription.created', ['status' => 'active']);

    postPaddleWebhook($this, $body, paddleSignature($body))
        ->assertOk()
        ->assertJsonPath('events', ['subscription.created']);

    postPaddleWebhook($this, $body, paddleSignature($body))
        ->assertOk()
        ->assertJsonPath('events', []);

    expect(BillingWebhookEvent::query()->count())->toBe(1);
});

it('records the customer id from transaction.completed without a subscription', function (): void {
    [$user, $organization] = paddleBillingOrgOwner();

    $body = paddleWebhookBody('transaction.completed', [
        'id' => 'txn_test_1',
        'customer_id' => 'ctm_test_789',
        'subscription_id' => null,
        'status' => 'completed',
        'custom_data' => ['organization_id' => (string) $organization->id],
    ]);

    postPaddleWebhook($this, $body, paddleSignature($body))->assertOk();

    $organization->refresh();

    expect($organization->billing_customer_id)->toBe('ctm_test_789')
        ->and($organization->plan)->toBe(Organization::PLAN_TRIAL)
        ->and($organization->status)->toBe(Organization::STATUS_TRIALING);
});

it('confirms active on transaction.completed for a subscription renewal', function (): void {
    [$user, $organization] = paddleBillingOrgOwner();
    $organization->update(['plan' => Organization::PLAN_PAID, 'status' => Organization::STATUS_PAST_DUE, 'past_due_at' => now()->subDay()]);

    $body = paddleWebhookBody('transaction.completed', [
        'id' => 'txn_test_2',
        'customer_id' => 'ctm_test_456',
        'subscription_id' => 'sub_test_123',
        'status' => 'completed',
        'custom_data' => ['organization_id' => (string) $organization->id],
    ]);

    postPaddleWebhook($this, $body, paddleSignature($body))->assertOk();

    $organization->refresh();

    expect($organization->status)->toBe(Organization::STATUS_ACTIVE)
        ->and($organization->past_due_at)->toBeNull()
        ->and($organization->billing_subscription_id)->toBe('sub_test_123');
});

it('moves an org to past_due on transaction.payment_failed', function (): void {
    [$user, $organization] = paddleBillingOrgOwner();
    $organization->update(['plan' => Organization::PLAN_PAID, 'status' => Organization::STATUS_ACTIVE]);

    $body = paddleWebhookBody('transaction.payment_failed', [
        'id' => 'txn_test_3',
        'customer_id' => 'ctm_test_456',
        'subscription_id' => 'sub_test_123',
        'status' => 'past_due',
        'custom_data' => ['organization_id' => (string) $organization->id],
    ], 'evt_test_payfail');

    postPaddleWebhook($this, $body, paddleSignature($body))->assertOk();

    $organization->refresh();

    expect($organization->status)->toBe(Organization::STATUS_PAST_DUE)
        ->and($organization->past_due_at)->not->toBeNull();
});

it('accepts a webhook it cannot match to an organization', function (): void {
    $body = paddleWebhookBody('subscription.created', [
        'id' => 'sub_unknown',
        'customer_id' => 'ctm_unknown',
        'status' => 'active',
        'custom_data' => [],
    ]);

    // 200 (Paddle retries non-2xx): the delivery is recorded, nothing crashes.
    postPaddleWebhook($this, $body, paddleSignature($body))->assertOk();
});

it('creates a paddle checkout through the api with the org in custom data', function (): void {
    Http::fake([
        'api.paddle.com/customers' => Http::response([
            'data' => ['id' => 'ctm_test_456', 'email' => 'owner@example.com'],
        ], 201),
        'api.paddle.com/transactions' => Http::response([
            'data' => [
                'id' => 'txn_test_1',
                'checkout' => ['url' => 'https://checkout.paddle.com?_ptxn=txn_test_1'],
            ],
        ], 201),
    ]);

    [$user, $organization] = paddleBillingOrgOwner();

    $url = app(PaddleProvider::class)->createCheckout($organization, Organization::PLAN_PAID);

    expect($url)->toBe('https://checkout.paddle.com?_ptxn=txn_test_1');

    // The new Paddle customer id is stored on the org for later events.
    expect($organization->fresh()->billing_customer_id)->toBe('ctm_test_456')
        ->and($organization->fresh()->billing_provider)->toBe('paddle');

    Http::assertSent(function ($request) use ($organization): bool {
        $data = $request->data();

        return $request->url() === 'https://api.paddle.com/transactions'
            && $data['items'][0]['price_id'] === 'pri_test_123'
            && $data['items'][0]['quantity'] === 1
            && $data['customer_id'] === 'ctm_test_456'
            && $data['custom_data']['organization_id'] === (string) $organization->id;
    });
});

it('reuses the stored paddle customer id on a later checkout', function (): void {
    Http::fake([
        'api.paddle.com/transactions' => Http::response([
            'data' => [
                'id' => 'txn_test_2',
                'checkout' => ['url' => 'https://checkout.paddle.com?_ptxn=txn_test_2'],
            ],
        ], 201),
    ]);

    [$user, $organization] = paddleBillingOrgOwner();
    $organization->update(['billing_customer_id' => 'ctm_existing_1', 'billing_provider' => 'paddle']);

    $url = app(PaddleProvider::class)->createCheckout($organization, Organization::PLAN_PAID);

    expect($url)->toBe('https://checkout.paddle.com?_ptxn=txn_test_2');

    // No customer creation call: the stored id was reused.
    Http::assertNotSent(function ($request): bool {
        return $request->url() === 'https://api.paddle.com/customers';
    });
});

it('returns the portal url from a portal session', function (): void {
    Http::fake([
        'api.paddle.com/customers/*/portal-sessions' => Http::response([
            'data' => ['urls' => ['general' => ['overview' => 'https://customer-portal.paddle.com/cpl_test_1?action=overview&token=abc']]],
        ], 201),
    ]);

    [$user, $organization] = paddleBillingOrgOwner();
    $organization->update(['billing_customer_id' => 'ctm_test_456', 'billing_subscription_id' => 'sub_test_123']);

    $url = app(PaddleProvider::class)->portalUrl($organization);

    expect($url)->toBe('https://customer-portal.paddle.com/cpl_test_1?action=overview&token=abc');

    Http::assertSent(function ($request): bool {
        $data = $request->data();

        return $request->url() === 'https://api.paddle.com/customers/ctm_test_456/portal-sessions'
            && $data['subscription_ids'] === ['sub_test_123'];
    });
});

it('returns null for the portal url without a paddle customer id', function (): void {
    [$user, $organization] = paddleBillingOrgOwner();

    expect(app(PaddleProvider::class)->portalUrl($organization))->toBeNull();
});

it('cancels at period end through the api', function (): void {
    Http::fake([
        'api.paddle.com/subscriptions/*/cancel' => Http::response([
            'data' => ['id' => 'sub_test_123', 'status' => 'active'],
        ], 200),
    ]);

    [$user, $organization] = paddleBillingOrgOwner();
    $organization->update([
        'plan' => Organization::PLAN_PAID,
        'status' => Organization::STATUS_ACTIVE,
        'billing_subscription_id' => 'sub_test_123',
    ]);

    app(PaddleProvider::class)->cancelSubscription($organization);

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.paddle.com/subscriptions/sub_test_123/cancel'
            && $request->data()['effective_from'] === 'next_billing_period';
    });

    // Local state is updated by the subscription.canceled webhook, not
    // by the cancel call — the org keeps access until period end.
    expect($organization->fresh()->status)->toBe(Organization::STATUS_ACTIVE);
});

it('reports paddle unconfigured without credentials', function (): void {
    config()->set('billing.paddle.api_key', '');
    config()->set('billing.paddle.price_pro', '');

    expect(app(PaddleProvider::class)->isConfigured())->toBeFalse();

    config()->set('billing.paddle.api_key', 'test-api-key');
    config()->set('billing.paddle.price_pro', 'pri_test_123');

    expect(app(PaddleProvider::class)->isConfigured())->toBeTrue();
});

it('refuses checkout when paddle is not configured', function (): void {
    config()->set('billing.paddle.api_key', '');
    config()->set('billing.paddle.price_pro', '');

    [$user, $organization] = paddleBillingOrgOwner();

    try {
        app(PaddleProvider::class)->createCheckout($organization, Organization::PLAN_PAID);
        $this->fail('Expected createCheckout to throw when Paddle is not configured.');
    } catch (BillingNotConfiguredException $exception) {
        expect($exception->getMessage())->toContain('Paddle is not configured');
    }
});
