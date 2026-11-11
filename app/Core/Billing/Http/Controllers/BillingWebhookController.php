<?php

namespace App\Core\Billing\Http\Controllers;

use App\Core\Billing\Exceptions\BillingNotConfiguredException;
use App\Core\Billing\Exceptions\InvalidWebhookSignatureException;
use App\Core\Billing\Services\BillingManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;

/**
 * Receives Lemon Squeezy webhooks (§2). CSRF-exempt (see
 * bootstrap/app.php) — authenticity comes from the X-Signature HMAC,
 * verified over the raw body before anything else.
 */
class BillingWebhookController
{
    public function __invoke(Request $request, BillingManager $billing): JsonResponse
    {
        try {
            $events = $billing->provider()->handleWebhook(
                $request->getContent(),
                $request->header('X-Signature'),
                $request->header('X-Event-Name'),
            );
        } catch (InvalidWebhookSignatureException) {
            return response()->json(['error' => 'Invalid webhook signature.'], 401);
        } catch (BillingNotConfiguredException) {
            return response()->json(['error' => 'Billing is not configured.'], 503);
        } catch (JsonException) {
            return response()->json(['error' => 'Invalid JSON body.'], 400);
        }

        return response()->json(['received' => true, 'events' => $events]);
    }
}
