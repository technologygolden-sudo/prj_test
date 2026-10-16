<?php

use App\Http\Controllers\Admin\SiteController;
use App\Http\Controllers\Stock\EmplacementController;
use App\Http\Controllers\Stock\TransfertController;
use Illuminate\Support\Facades\Route;

/*
| Emplacements, transferts entre sites, SAV et Lab (chargé depuis routes/web.php, dans le groupe auth + mfa).
*/

Route::post('/emplacements/deplacer', [EmplacementController::class, 'deplacer'])->middleware('permission:stock.deplacer')->name('emplacements.deplacer');
Route::post('/emplacements/ceder', [EmplacementController::class, 'ceder'])->middleware('permission:stock.ceder')->name('emplacements.ceder');
Route::post('/etat/defaut', [EmplacementController::class, 'defaut'])->middleware('permission:sav.declarer')->name('etat.defaut');
Route::post('/etat/reparation', [EmplacementController::class, 'reparation'])->middleware('permission:lab.reparer')->name('etat.reparation');

Route::middleware('permission:transferts.envoyer|transferts.recevoir')->group(function () {
    Route::get('/transferts', [TransfertController::class, 'index'])->name('transferts.index');
    Route::get('/transferts/{transfert}', [TransfertController::class, 'show'])->name('transferts.show')->whereNumber('transfert');
});
Route::middleware('permission:transferts.envoyer')->group(function () {
    Route::get('/transferts/nouveau', [TransfertController::class, 'create'])->name('transferts.create');
    Route::post('/transferts/verifier', [TransfertController::class, 'verifier'])->name('transferts.verifier');
    Route::get('/transferts/disponibilites', [TransfertController::class, 'disponibilites'])->name('transferts.disponibilites');
    Route::post('/transferts', [TransfertController::class, 'store'])->name('transferts.store');
});
Route::post('/transferts/{transfert}/recevoir', [TransfertController::class, 'recevoir'])
    ->middleware('permission:transferts.recevoir')->whereNumber('transfert')->name('transferts.recevoir');

Route::middleware('permission:parametres.gerer')->group(function () {
    Route::post('/admin/sites', [SiteController::class, 'store'])->name('sites.store');
    Route::put('/admin/sites/{site}', [SiteController::class, 'update'])->name('sites.update');
    Route::put('/admin/emplacements/{emplacement}', [SiteController::class, 'emplacement'])->name('emplacements.update');
});
