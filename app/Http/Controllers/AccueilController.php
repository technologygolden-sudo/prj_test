<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AccueilController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Accueil', [
            'compteurs' => [
                'articles' => DB::table('article')->count(),
                'types_kit' => DB::table('type_kit')->count(),
                'brouillons' => DB::table('version_kit')->where('verrouillee', false)->count(),
                'unites_en_stock' => DB::table('unite_article')->where('statut', 'en_stock')->count(),
                'kits_assembles' => DB::table('kit_assemble')->where('statut', 'assemble')->count(),
                'transferts_en_transit' => DB::table('transfert')->where('statut', '<>', 'recu')->count(),
                'defectueux' => DB::table('unite_article')->where('etat', 'defectueux')->where('statut', 'en_stock')->count()
                    + DB::table('kit_assemble')->where('etat', 'defectueux')->where('statut', 'assemble')->count(),
                'a_imprimer' => DB::table('unite_article')->where('statut', 'en_stock')
                    ->whereNotExists(fn ($q) => $q->from('impression_etiquette')->whereColumn('unite_id', 'unite_article.id'))->count(),
            ],
        ]);
    }
}
