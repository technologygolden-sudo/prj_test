<?php

use App\Http\Controllers\Stock\InventaireController;
use Illuminate\Support\Facades\Route;

/*
| Campagnes d'inventaire (chargé depuis routes/web.php, dans le groupe auth + mfa).
*/

Route::middleware('permission:inventaire.compter|inventaire.gerer')->group(function () {
    Route::get('/inventaires', [InventaireController::class, 'index'])->name('inventaires.index');
    Route::get('/inventaires/{inventaire}', [InventaireController::class, 'show'])->name('inventaires.show')->whereNumber('inventaire');
});
Route::post('/inventaires/{inventaire}/compter', [InventaireController::class, 'compter'])
    ->middleware('permission:inventaire.compter')->whereNumber('inventaire')->name('inventaires.compter');

Route::middleware('permission:inventaire.gerer')->group(function () {
    Route::get('/inventaires/nouveau', [InventaireController::class, 'create'])->name('inventaires.create');
    Route::post('/inventaires', [InventaireController::class, 'store'])->name('inventaires.store');
    Route::get('/inventaires/{inventaire}/parametres', [InventaireController::class, 'edit'])->name('inventaires.edit')->whereNumber('inventaire');
    Route::put('/inventaires/{inventaire}', [InventaireController::class, 'update'])->name('inventaires.update')->whereNumber('inventaire');
    Route::post('/inventaires/{inventaire}/lancer', [InventaireController::class, 'lancer'])->name('inventaires.lancer')->whereNumber('inventaire');
    Route::post('/inventaires/{inventaire}/terminer-comptage', [InventaireController::class, 'terminerTour'])->name('inventaires.terminer')->whereNumber('inventaire');
    Route::post('/inventaires/{inventaire}/second-comptage', [InventaireController::class, 'secondComptage'])->name('inventaires.second')->whereNumber('inventaire');
    Route::post('/inventaires/{inventaire}/decompte', [InventaireController::class, 'decompte'])->name('inventaires.decompte')->whereNumber('inventaire');
    Route::put('/inventaires/lignes/{ligne}', [InventaireController::class, 'fixerFinal'])->name('inventaires.final')->whereNumber('ligne');
});
Route::post('/inventaires/{inventaire}/cloturer', [InventaireController::class, 'cloturer'])
    ->middleware('permission:inventaire.cloturer')->whereNumber('inventaire')->name('inventaires.cloturer');
