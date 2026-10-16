<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Audit;
use Database\Seeders\PermissionSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('app:creer-admin')]
#[Description('Crée un compte administrateur (premier accès à l’application)')]
class CreerAdministrateur extends Command
{
    public function handle(): int
    {
        $this->call('db:seed', ['--class' => PermissionSeeder::class, '--force' => true]);

        $donnees = [
            'login' => text('Login', required: true, validate: ['login' => 'regex:/^[a-z0-9._-]+$/|unique:users,login']),
            'name' => text('Nom complet', required: true),
            'email' => text('E-mail', required: true, validate: ['email' => 'email|unique:users,email']),
            'password' => password('Mot de passe (12 caractères min., majuscules, minuscules, chiffres)', required: true),
        ];

        $validation = Validator::make($donnees, ['password' => [Password::defaults()]]);
        if ($validation->fails()) {
            $this->error($validation->errors()->first('password'));

            return self::FAILURE;
        }

        $user = User::create($donnees + ['actif' => true]);
        $user->assignRole(PermissionSeeder::ADMINISTRATEUR);
        Audit::enregistrer('creer', $user, apres: $user->only(['login', 'name', 'email']) + ['roles' => [PermissionSeeder::ADMINISTRATEUR]]);

        $this->info("Administrateur {$user->login} créé. À la première connexion, il devra activer la double authentification.");

        return self::SUCCESS;
    }
}
