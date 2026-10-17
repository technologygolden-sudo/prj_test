<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'app' => [
                'nom' => config('app.name'),
            ],
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'login' => $user->login,
                    'name' => $user->name,
                    'mfa' => $user->aMfaActive(),
                    'roles' => $user->getRoleNames(),
                ] : null,
                // Sert à masquer les boutons ; le contrôle réel reste côté serveur (RG22).
                'permissions' => $user ? $user->getAllPermissions()->pluck('name') : [],
            ],
            // Suggestions des champs (types d'article, motifs, techniciens), gérées dans Référentiel › Listes de valeurs.
            'listes' => fn () => $user ? \App\Http\Controllers\Referentiel\ListeValeurController::partagees() : [],
            'flash' => [
                'succes' => fn () => $request->session()->get('succes'),
                'alerte' => fn () => $request->session()->get('alerte'),
                'status' => fn () => $request->session()->get('status'),
            ],
        ];
    }
}
