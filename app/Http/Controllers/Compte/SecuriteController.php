<?php

namespace App\Http\Controllers\Compte;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Page Sécurité du compte : mot de passe et double authentification.
 * Les actions passent par les routes de Fortify (/user/password, /user/two-factor-*).
 */
class SecuriteController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Compte/Securite', [
            'mfaActive' => $user->aMfaActive(),
            'mfaEnCours' => $user->two_factor_secret !== null && ! $user->aMfaActive(),
            'mfaExigee' => $user->exigeMfa(),
        ]);
    }
}
