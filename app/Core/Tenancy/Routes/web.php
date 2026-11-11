<?php

use App\Core\Tenancy\Http\Controllers\OrganizationController;
use App\Core\Tenancy\Http\Controllers\SignupController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

// SaaS signup (§3). Fortify registration stays OFF — this is the only
// self-serve registration path. The Volt page and the POST share
// SignupService; the POST is throttled (public form).
Route::middleware(['web', 'guest'])->group(function (): void {
    Volt::route('signup', 'signup')->name('signup');

    Route::post('signup', SignupController::class)
        ->middleware('throttle:5,1')
        ->name('signup.store');
});

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::post('organizations', [OrganizationController::class, 'store'])->name('organizations.store');
    Route::get('organizations/mine', [OrganizationController::class, 'mine'])->name('organizations.mine');
    Route::post('organizations/{organization}/members', [OrganizationController::class, 'addMember'])->name('organizations.members.store');
});
