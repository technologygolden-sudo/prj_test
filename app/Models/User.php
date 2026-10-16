<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['login', 'name', 'email', 'password', 'actif'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'derniere_connexion' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'actif' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /** ENF01 : un utilisateur qui détient une permission sensible (★) doit avoir activé la MFA. */
    public function exigeMfa(): bool
    {
        return config('securite.mfa_obligatoire')
            && $this->getAllPermissions()->contains(fn ($permission) => $permission->sensible);
    }

    public function aMfaActive(): bool
    {
        // Un modèle tout juste créé n'a pas encore la colonne chargée : absence = MFA non activée.
        return ($this->getAttributes()['two_factor_confirmed_at'] ?? null) !== null;
    }
}
