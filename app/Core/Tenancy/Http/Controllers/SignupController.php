<?php

namespace App\Core\Tenancy\Http\Controllers;

use App\Core\Tenancy\Services\SignupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * SaaS signup (§3). Fortify registration stays OFF; this is the only
 * self-serve registration path. Throttled (public form).
 */
class SignupController
{
    public function __invoke(Request $request, SignupService $signup): RedirectResponse
    {
        $result = $signup->register($request->all());

        Auth::login($result['user']);

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }
}
