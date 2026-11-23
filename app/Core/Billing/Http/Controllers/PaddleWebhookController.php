<?php

namespace App\Core\Billing\Http\Controllers;

use App\Core\Billing\Exceptions\BillingNotConfiguredException;
use App\Core\Billing\Exceptions\InvalidWebhookSignatureException;
use App\Core\Billing\Providers\PaddleProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;

/**
 * Receives Paddle webhooks. CSRF-exempt (see bootstrap/app.php) —
 * authenticity comes from the Paddle-Signature HMAC, verified over
 * the raw body before anything else. The event type lives in the
 * payload (`event_type`); Paddle sends no event-name header.
 */
class PaddleWebhookController
{
    public function __invoke(Request $request, PaddleProvider $paddle): JsonResponse
    {
        try {
            $events = $paddle->handleWebhook(
                $request->getContent(),
                $request->header('Paddle-Signature'),
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
