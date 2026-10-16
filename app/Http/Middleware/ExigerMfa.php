<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ENF01 : tant qu'un utilisateur à permission sensible n'a pas activé la MFA,
 * il est renvoyé vers la page Sécurité de son compte.
 */
class ExigerMfa
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->exigeMfa() && ! $user->aMfaActive()) {
            return redirect()->route('compte.securite')
                ->with('alerte', 'Activez la double authentification pour accéder à l’application.');
        }

        return $next($request);
    }
}
