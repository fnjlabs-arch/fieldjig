<?php

namespace App\Core\Billing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Idempotency record for billing webhook deliveries (§2).
 *
 * Lemon Squeezy sends no per-delivery id, so event_id is
 * "lemonsqueezy:" + sha256(raw request body) — an exact replay of a
 * delivery is skipped; a redelivery with different content is a new
 * event. The unique index makes the skip race-safe.
 */
class BillingWebhookEvent extends Model
{
    protected $fillable = [
        'provider',
        'event_id',
        'processed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
        ];
    }
}
