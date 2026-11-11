<?php

use App\Core\Billing\Http\Controllers\BillingCancelController;
use App\Core\Billing\Http\Controllers\BillingCheckoutController;
use App\Core\Billing\Http\Controllers\BillingWebhookController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::livewire('billing', 'billing::panel')->name('billing.panel');
    Route::post('billing/checkout', BillingCheckoutController::class)->name('billing.checkout');
    Route::post('billing/cancel', BillingCancelController::class)->name('billing.cancel');
});

// No auth: authenticity comes from the X-Signature HMAC. CSRF-exempt
// in bootstrap/app.php.
Route::middleware(['web'])
    ->post('billing/webhook/lemonsqueezy', BillingWebhookController::class)
    ->name('billing.webhook.lemonsqueezy');
