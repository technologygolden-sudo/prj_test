<?php

namespace App\Providers;

use App\Actions\Fortify\UpdateUserPassword;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        // Seuls les comptes actifs se connectent ; la connexion est horodatée et journalisée.
        Fortify::authenticateUsing(function (Request $request) {
            $user = User::where('login', Str::lower($request->input('login')))->first();

            if (! $user || ! $user->actif || ! Hash::check($request->input('password'), $user->password)) {
                return null;
            }

            $user->forceFill(['derniere_connexion' => now()])->save();
            Audit::enregistrer('connexion', $user, utilisateurId: $user->id);

            return $user;
        });

        Fortify::loginView(fn () => Inertia::render('Auth/Connexion'));
        Fortify::twoFactorChallengeView(fn () => Inertia::render('Auth/DefiMfa'));
        Fortify::confirmPasswordView(fn () => Inertia::render('Auth/ConfirmerMotDePasse'));

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });
    }
}
