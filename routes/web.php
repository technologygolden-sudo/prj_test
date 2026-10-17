<?php

use App\Http\Controllers\AccueilController;
use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\ParametreController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UtilisateurController;
use App\Http\Controllers\Compte\SecuriteController;
use App\Http\Controllers\Referentiel\ArticleController;
use App\Http\Controllers\Referentiel\CategorieController;
use App\Http\Controllers\Referentiel\FabricantController;
use App\Http\Controllers\Referentiel\ImportController;
use App\Http\Controllers\Referentiel\ListeValeurController;
use App\Http\Controllers\Referentiel\TypeKitController;
use App\Http\Controllers\Referentiel\VersionKitController;
use App\Http\Controllers\Simulation\CapaciteController;
use App\Http\Controllers\Simulation\SimulationController;
use App\Http\Controllers\Stock\AssemblageController;
use App\Http\Controllers\Stock\EtiquetteController;
use App\Http\Controllers\Stock\KitAssembleController;
use App\Http\Controllers\Stock\ReceptionController;
use App\Http\Controllers\Stock\RechercheController;
use App\Http\Controllers\Stock\SortieController;
use App\Http\Controllers\Stock\UniteController;
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
            Route::get('/stock', [UniteController::class, 'index'])->name('stock.index');
            Route::get('/stock/{unite}', [UniteController::class, 'show'])->name('stock.show')->whereNumber('unite');
            Route::get('/kits-assembles', [KitAssembleController::class, 'index'])->name('kits-assembles.index');
            Route::get('/kits-assembles/{kit}', [KitAssembleController::class, 'show'])->name('kits-assembles.show')->whereNumber('kit');
        });

        Route::get('/recherche', RechercheController::class)->middleware('permission:recherche.scanner')->name('recherche');

        Route::middleware('permission:articles.gerer')->group(function () {
            Route::get('/categories', [CategorieController::class, 'index'])->name('categories.index');
            Route::post('/categories', [CategorieController::class, 'store'])->name('categories.store');
            Route::put('/categories/{categorie}', [CategorieController::class, 'update'])->name('categories.update');
            Route::delete('/categories/{categorie}', [CategorieController::class, 'destroy'])->name('categories.destroy');

            Route::get('/listes', [ListeValeurController::class, 'index'])->name('listes.index');
            Route::post('/listes', [ListeValeurController::class, 'store'])->name('listes.store');
            Route::put('/listes/{valeur}', [ListeValeurController::class, 'update'])->name('listes.update');
            Route::delete('/listes/{valeur}', [ListeValeurController::class, 'destroy'])->name('listes.destroy');

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

        Route::middleware(['permission:articles.gerer', 'permission:kits.gerer'])->group(function () {
            Route::get('/import', [ImportController::class, 'index'])->name('import.index');
            Route::get('/import/modele', [ImportController::class, 'modele'])->name('import.modele');
            Route::post('/import/analyser', [ImportController::class, 'analyser'])->name('import.analyser');
            Route::post('/import/appliquer', [ImportController::class, 'appliquer'])->name('import.appliquer');
            Route::post('/import/annuler', [ImportController::class, 'annuler'])->name('import.annuler');
        });

        Route::post('/versions/{version}/verrouiller', [VersionKitController::class, 'verrouiller'])
            ->middleware('permission:kits.verrouiller')->name('versions.verrouiller');

        // Stock
        Route::middleware('permission:reception.creer')->group(function () {
            Route::get('/receptions', [ReceptionController::class, 'index'])->name('receptions.index');
            Route::get('/receptions/nouvelle', [ReceptionController::class, 'create'])->name('receptions.create');
            Route::post('/receptions', [ReceptionController::class, 'store'])->name('receptions.store');
            Route::get('/receptions/{reception}', [ReceptionController::class, 'show'])->name('receptions.show')->whereNumber('reception');
        });

        Route::middleware('permission:stock.sortir')->group(function () {
            Route::get('/sorties', [SortieController::class, 'index'])->name('sorties.index');
            Route::get('/sorties/nouvelle', [SortieController::class, 'create'])->name('sorties.create');
            Route::post('/sorties/verifier', [SortieController::class, 'verifier'])->name('sorties.verifier');
            Route::post('/sorties', [SortieController::class, 'store'])->name('sorties.store');
        });

        Route::post('/stock/{unite}/ajuster', [UniteController::class, 'ajuster'])
            ->middleware('permission:stock.ajuster')->name('stock.ajuster');

        Route::middleware('permission:assemblage.realiser')->group(function () {
            Route::get('/assemblage', [AssemblageController::class, 'create'])->name('assemblage.create');
            Route::post('/assemblage/verifier', [AssemblageController::class, 'verifier'])->name('assemblage.verifier');
            Route::post('/assemblage', [AssemblageController::class, 'store'])->name('assemblage.store');
        });

        Route::post('/kits-assembles/{kit}/desassembler', [KitAssembleController::class, 'desassembler'])
            ->middleware('permission:assemblage.annuler')->name('kits-assembles.desassembler');

        require __DIR__.'/emplacements.php';
        require __DIR__.'/inventaires.php';

        Route::post('/etiquettes/imprimer', [EtiquetteController::class, 'imprimer'])
            ->middleware('permission:etiquettes.imprimer')->name('etiquettes.imprimer');

        // Simulation
        Route::get('/capacite', CapaciteController::class)->middleware('permission:capacite.consulter')->name('capacite');

        Route::middleware('permission:simulation.lancer')->group(function () {
            Route::get('/simulations', [SimulationController::class, 'index'])->name('simulations.index');
            Route::get('/simulations/nouvelle', [SimulationController::class, 'create'])->name('simulations.create');
            Route::post('/simulations', [SimulationController::class, 'store'])->name('simulations.store');
            Route::get('/simulations/{simulation}', [SimulationController::class, 'show'])->name('simulations.show')->whereNumber('simulation');
        });

        Route::get('/simulations/{simulation}/export/{format}', [SimulationController::class, 'exporter'])
            ->middleware('permission:simulation.exporter')->whereNumber('simulation')->whereIn('format', ['csv', 'pdf', 'achats'])
            ->name('simulations.exporter');

        // Administration
        Route::middleware('permission:utilisateurs.gerer')->group(function () {
            Route::get('/admin/utilisateurs', [UtilisateurController::class, 'index'])->name('utilisateurs.index');
            Route::post('/admin/utilisateurs', [UtilisateurController::class, 'store'])->name('utilisateurs.store');
            Route::put('/admin/utilisateurs/{utilisateur}', [UtilisateurController::class, 'update'])->name('utilisateurs.update');
            Route::post('/admin/utilisateurs/{utilisateur}/reinitialiser-mfa', [UtilisateurController::class, 'reinitialiserMfa'])
                ->name('utilisateurs.reinitialiser-mfa');
        });

        Route::middleware('permission:roles.gerer')->group(function () {
            Route::get('/admin/roles', [RoleController::class, 'index'])->name('roles.index');
            Route::post('/admin/roles', [RoleController::class, 'store'])->name('roles.store');
            Route::put('/admin/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
            Route::delete('/admin/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
        });

        Route::middleware('permission:parametres.gerer')->group(function () {
            Route::get('/admin/parametres', [ParametreController::class, 'index'])->name('parametres.index');
            Route::put('/admin/parametres/societe', [ParametreController::class, 'societe'])->name('parametres.societe');
            Route::post('/admin/imprimantes', [ParametreController::class, 'enregistrerImprimante'])->name('imprimantes.store');
            Route::put('/admin/imprimantes/{imprimante}', [ParametreController::class, 'enregistrerImprimante'])->name('imprimantes.update');
            Route::put('/admin/modeles/{modele}', [ParametreController::class, 'modele'])->name('modeles.update');
        });

        Route::middleware('permission:audit.consulter')->group(function () {
            Route::get('/admin/audit', [AuditController::class, 'index'])->name('audit.index');
            Route::get('/admin/audit/export', [AuditController::class, 'exporter'])->name('audit.exporter');
        });
    });
});
