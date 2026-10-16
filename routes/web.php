<?php

use App\Http\Controllers\AccueilController;
use App\Http\Controllers\Admin\UtilisateurController;
use App\Http\Controllers\Compte\SecuriteController;
use App\Http\Controllers\Referentiel\ArticleController;
use App\Http\Controllers\Referentiel\CategorieController;
use App\Http\Controllers\Referentiel\FabricantController;
use App\Http\Controllers\Referentiel\TypeKitController;
use App\Http\Controllers\Referentiel\VersionKitController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    // Accessible sans MFA : c'est là qu'on l'active (ENF01).
    Route::get('/compte/securite', SecuriteController::class)->name('compte.securite');

    Route::middleware('mfa')->group(function () {
        Route::get('/', AccueilController::class)->name('accueil');

        // Référentiel (RG22 : chaque route vérifie sa permission côté serveur).
        Route::middleware('permission:articles.voir')->group(function () {
            Route::get('/articles', [ArticleController::class, 'index'])->name('articles.index');
            Route::get('/kits', [TypeKitController::class, 'index'])->name('kits.index');
            Route::get('/kits/{kit}', [TypeKitController::class, 'show'])->name('kits.show')->whereNumber('kit');
        });

        Route::middleware('permission:articles.gerer')->group(function () {
            Route::get('/categories', [CategorieController::class, 'index'])->name('categories.index');
            Route::post('/categories', [CategorieController::class, 'store'])->name('categories.store');
            Route::put('/categories/{categorie}', [CategorieController::class, 'update'])->name('categories.update');
            Route::delete('/categories/{categorie}', [CategorieController::class, 'destroy'])->name('categories.destroy');

            Route::get('/fabricants', [FabricantController::class, 'index'])->name('fabricants.index');
            Route::post('/fabricants', [FabricantController::class, 'store'])->name('fabricants.store');
            Route::put('/fabricants/{fabricant}', [FabricantController::class, 'update'])->name('fabricants.update');
            Route::delete('/fabricants/{fabricant}', [FabricantController::class, 'destroy'])->name('fabricants.destroy');

            Route::get('/articles/nouveau', [ArticleController::class, 'create'])->name('articles.create');
            Route::post('/articles', [ArticleController::class, 'store'])->name('articles.store');
            Route::get('/articles/{article}/modifier', [ArticleController::class, 'edit'])->name('articles.edit');
            Route::put('/articles/{article}', [ArticleController::class, 'update'])->name('articles.update');
            Route::delete('/articles/{article}', [ArticleController::class, 'destroy'])->name('articles.destroy');
        });

        Route::middleware('permission:kits.gerer')->group(function () {
            Route::get('/kits/nouveau', [TypeKitController::class, 'create'])->name('kits.create');
            Route::post('/kits', [TypeKitController::class, 'store'])->name('kits.store');
            Route::put('/kits/{kit}', [TypeKitController::class, 'update'])->name('kits.update');
            Route::delete('/kits/{kit}', [TypeKitController::class, 'destroy'])->name('kits.destroy');

            Route::post('/kits/{kit}/versions', [VersionKitController::class, 'store'])->name('versions.store');
            Route::put('/versions/{version}', [VersionKitController::class, 'update'])->name('versions.update');
            Route::delete('/versions/{version}', [VersionKitController::class, 'destroy'])->name('versions.destroy');
        });

        Route::post('/versions/{version}/verrouiller', [VersionKitController::class, 'verrouiller'])
            ->middleware('permission:kits.verrouiller')->name('versions.verrouiller');

        // Administration
        Route::middleware('permission:utilisateurs.gerer')->group(function () {
            Route::get('/admin/utilisateurs', [UtilisateurController::class, 'index'])->name('utilisateurs.index');
            Route::post('/admin/utilisateurs', [UtilisateurController::class, 'store'])->name('utilisateurs.store');
            Route::put('/admin/utilisateurs/{utilisateur}', [UtilisateurController::class, 'update'])->name('utilisateurs.update');
            Route::post('/admin/utilisateurs/{utilisateur}/reinitialiser-mfa', [UtilisateurController::class, 'reinitialiserMfa'])
                ->name('utilisateurs.reinitialiser-mfa');
        });
    });
});
