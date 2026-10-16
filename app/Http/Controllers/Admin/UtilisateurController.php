<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class UtilisateurController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Utilisateurs', [
            'utilisateurs' => User::with('roles:id,name')->orderBy('login')->get()->map(fn (User $u) => [
                'id' => $u->id,
                'login' => $u->login,
                'name' => $u->name,
                'email' => $u->email,
                'actif' => $u->actif,
                'mfa' => $u->aMfaActive(),
                'roles' => $u->roles->pluck('name'),
                'derniere_connexion' => $u->derniere_connexion?->format('d/m/Y H:i'),
            ]),
            'roles' => Role::where('actif', true)->orderBy('name')->pluck('name'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $donnees = $request->validate([
            'login' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9._-]+$/', Rule::unique('users', 'login')],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', Password::defaults()],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::exists('roles', 'name')->where('actif', true)],
        ], ['login.regex' => 'Le login ne contient que des minuscules, chiffres, points, tirets et soulignés.']);

        $user = DB::transaction(function () use ($request, $donnees) {
            $user = User::create(collect($donnees)->except('roles')->all() + ['actif' => true]);
            $user->syncRoles($donnees['roles']);
            $this->tracerAttribution($user, $request->user()->id);
            Audit::enregistrer('creer', $user, apres: $user->only(['login', 'name', 'email', 'actif']) + ['roles' => $donnees['roles']]);

            return $user;
        });

        return back()->with('succes', "Utilisateur {$user->login} créé. Transmettez-lui son mot de passe provisoire par un canal sûr.");
    }

    public function update(Request $request, User $utilisateur): RedirectResponse
    {
        $donnees = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($utilisateur)],
            'actif' => ['required', 'boolean'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::exists('roles', 'name')->where('actif', true)],
        ]);

        $moi = $request->user();
        $rolesAvant = $utilisateur->getRoleNames()->sort()->values()->all();
        $rolesApres = collect($donnees['roles'])->sort()->values()->all();

        // RG18 : personne ne modifie ses propres rôles.
        if ($moi->is($utilisateur) && ($rolesAvant !== $rolesApres || ! $donnees['actif'])) {
            return back()->with('alerte', 'Vous ne pouvez pas modifier vos propres rôles ni désactiver votre compte.');
        }

        // RG17 : il reste toujours au moins un administrateur actif.
        $resteAdmin = in_array(PermissionSeeder::ADMINISTRATEUR, $donnees['roles'], true) && $donnees['actif'];
        if ($utilisateur->hasRole(PermissionSeeder::ADMINISTRATEUR) && ! $resteAdmin && $this->administrateursActifs() <= 1) {
            return back()->with('alerte', 'C’est le dernier administrateur actif : il doit le rester.');
        }

        $avant = $utilisateur->only(['name', 'email', 'actif']) + ['roles' => $rolesAvant];

        DB::transaction(function () use ($utilisateur, $donnees, $moi, $avant, $rolesAvant, $rolesApres) {
            $utilisateur->update(collect($donnees)->only(['name', 'email', 'actif'])->all());
            if ($rolesAvant !== $rolesApres) {
                $utilisateur->syncRoles($donnees['roles']);
                $this->tracerAttribution($utilisateur, $moi->id);
            }
            Audit::enregistrer('modifier', $utilisateur, $avant, $utilisateur->only(['name', 'email', 'actif']) + ['roles' => $rolesApres]);
        });

        return back()->with('succes', "Utilisateur {$utilisateur->login} modifié.");
    }

    public function reinitialiserMfa(Request $request, User $utilisateur): RedirectResponse
    {
        if ($request->user()->is($utilisateur)) {
            return back()->with('alerte', 'Gérez votre propre double authentification depuis la page Sécurité.');
        }

        $utilisateur->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
        Audit::enregistrer('reinitialiser_mfa', $utilisateur);

        return back()->with('succes', "Double authentification de {$utilisateur->login} réinitialisée : il devra la réactiver à sa prochaine connexion.");
    }

    private function administrateursActifs(): int
    {
        return User::role(PermissionSeeder::ADMINISTRATEUR)->where('actif', true)->count();
    }

    private function tracerAttribution(User $user, int $auteurId): void
    {
        DB::table(config('permission.table_names.model_has_roles'))
            ->where('model_id', $user->id)->where('model_type', $user->getMorphClass())
            ->update(['attribue_par' => $auteurId, 'attribue_le' => now()]);
    }
}
