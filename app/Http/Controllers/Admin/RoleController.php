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
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Rôles et permissions (section 7, RG16, RG19, RG20, RG21, RG23). */
class RoleController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Roles', [
            'permissions' => Permission::orderBy('module')->orderBy('name')->get(['name', 'module', 'libelle', 'sensible']),
            'roles' => Role::with('permissions:id,name')->withCount('users')->orderByDesc('systeme')->orderBy('name')->get()
                ->map(fn (Role $r) => [
                    'id' => $r->id, 'name' => $r->name, 'systeme' => (bool) $r->systeme, 'actif' => (bool) $r->actif,
                    'utilisateurs' => $r->users_count, 'permissions' => $r->permissions->pluck('name'),
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $donnees = $request->validate([
            'name' => ['required', 'string', 'max:64', Rule::unique('roles', 'name')],
            'copie_de' => ['nullable', 'integer', Rule::exists('roles', 'id')],
        ]);

        DB::transaction(function () use ($donnees) {
            $role = Role::create(['name' => $donnees['name'], 'guard_name' => 'web', 'systeme' => false, 'actif' => true]);
            if ($source = Role::find($donnees['copie_de'] ?? 0)) {
                // Duplication : les permissions sensibles ne sont pas recopiées sans validation MFA (RG19).
                $role->syncPermissions($source->permissions->reject(fn ($p) => $p->sensible));
            }
            Audit::enregistrer('creer', 'roles', apres: ['id' => $role->id, 'name' => $role->name, 'permissions' => $role->permissions->pluck('name')]);
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return back()->with('succes', "Rôle « {$donnees['name']} » créé.");
    }

    public function update(Request $request, Role $role, TwoFactorAuthenticationProvider $mfa): RedirectResponse
    {
        $donnees = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')], // RG23 : catalogue uniquement
            'actif' => ['required', 'boolean'],
            'code_mfa' => ['nullable', 'string', 'size:6'],
        ]);
        /** @var User $moi */
        $moi = $request->user();

        // RG16 : le rôle Administrateur est figé et garde tout le catalogue.
        if ($role->systeme) {
            return back()->with('alerte', 'Le rôle Administrateur est un rôle système : il ne peut pas être modifié.');
        }
        // RG18 : personne ne modifie un rôle qu'il détient.
        if ($moi->hasRole($role->name)) {
            return back()->with('alerte', 'Vous ne pouvez pas modifier un rôle que vous détenez.');
        }

        $avant = $role->permissions->pluck('name')->sort()->values()->all();
        $apres = collect($donnees['permissions'])->unique()->sort()->values()->all();
        $sensiblesTouchees = Permission::whereIn('name', array_merge(array_diff($avant, $apres), array_diff($apres, $avant)))
            ->where('sensible', true)->exists();

        // RG19 : accorder ou retirer une permission ★ exige une nouvelle validation MFA.
        if ($sensiblesTouchees) {
            $secret = $moi->two_factor_secret ? decrypt($moi->two_factor_secret) : null;
            if (! $secret || ! $mfa->verify($secret, (string) ($donnees['code_mfa'] ?? ''))) {
                throw ValidationException::withMessages(['code_mfa' => 'Une permission sensible change : saisissez votre code de double authentification.']);
            }
        }

        DB::transaction(function () use ($role, $donnees, $avant, $apres) {
            $role->syncPermissions($apres);
            $role->forceFill(['actif' => $donnees['actif']])->save();
            Audit::enregistrer('modifier', $role, ['permissions' => $avant, 'actif' => (bool) $role->getOriginal('actif')], ['permissions' => $apres, 'actif' => $donnees['actif']]);
        });
        // RG21 : effet dès la requête suivante.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return back()->with('succes', "Rôle « {$role->name} » enregistré.");
    }

    public function destroy(Role $role): RedirectResponse
    {
        if ($role->systeme || $role->name === PermissionSeeder::ADMINISTRATEUR) {
            return back()->with('alerte', 'Le rôle Administrateur ne peut pas être supprimé.');
        }
        if ($role->users()->exists()) {
            return back()->with('alerte', 'Ce rôle est attribué à des utilisateurs : retirez-le d’abord.');
        }
        Audit::enregistrer('supprimer', $role, ['name' => $role->name, 'permissions' => $role->permissions->pluck('name')]);
        $role->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return back()->with('succes', 'Rôle supprimé.');
    }
}
